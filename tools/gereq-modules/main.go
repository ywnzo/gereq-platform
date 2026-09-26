package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"

	"github.com/charmbracelet/bubbles/spinner"
	"github.com/charmbracelet/bubbles/viewport"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"
	"github.com/joho/godotenv"
)

type localModule struct {
	Dir     string
	ID      string
	Version string
	Name    string
}

type compareRow struct {
	Repo   string
	Local  string
	GitLab string
	Status string // ok | DIFF | NO_TAGS | MISSING
}

type scanResult struct {
	Local   []localModule
	GitLab  []string
	Missing []string
	Extra   []string
	Compare []compareRow
	Err     error
}

type config struct {
	Root   string
	URL    string
	Token  string
	Group  string
	Prefix string
}

func loadConfig() (config, error) {
	root, err := findProjectRoot()
	if err != nil {
		return config{}, err
	}
	_ = godotenv.Load(filepath.Join(root, ".env"))

	cfg := config{
		Root:   root,
		URL:    strings.TrimRight(envOr("GITLAB_URL", "https://git.gereq.cz"), "/"),
		Token:  strings.TrimSpace(os.Getenv("GITLAB_TOKEN")),
		Group:  envOr("GITLAB_GROUP", "gereq"),
		Prefix: envOr("MODULE_REPO_PREFIX", "module-"),
	}
	if cfg.Token == "" {
		return cfg, fmt.Errorf("GITLAB_TOKEN missing in .env")
	}
	return cfg, nil
}

func envOr(k, def string) string {
	if v := strings.TrimSpace(os.Getenv(k)); v != "" {
		return v
	}
	return def
}

func findProjectRoot() (string, error) {
	if v := os.Getenv("GEREQ_ROOT"); v != "" {
		return v, nil
	}
	wd, err := os.Getwd()
	if err != nil {
		return "", err
	}
	dir := wd
	for {
		if fileExists(filepath.Join(dir, "modules")) && fileExists(filepath.Join(dir, ".env")) {
			return dir, nil
		}
		parent := filepath.Dir(dir)
		if parent == dir {
			break
		}
		dir = parent
	}
	// fallback: script lives in tools/gereq-modules
	self, err := os.Executable()
	if err == nil {
		dir = filepath.Dir(self)
		for i := 0; i < 4; i++ {
			if fileExists(filepath.Join(dir, "modules")) {
				return dir, nil
			}
			dir = filepath.Dir(dir)
		}
	}
	return "", fmt.Errorf("nenalezen root projektu (modules/ + .env)")
}

func fileExists(p string) bool {
	_, err := os.Stat(p)
	return err == nil
}

func scanLocal(root string) ([]localModule, error) {
	script := `
foreach (glob("modules/*/module.php") as $f) {
  $m = include $f;
  $dir = basename(dirname($f));
  echo $dir."|".($m["id"]??"")."|".($m["version"]??"")."|".($m["name"]??$dir)."\n";
}
`
	cmd := exec.Command("php", "-r", script)
	cmd.Dir = root
	out, err := cmd.Output()
	if err != nil {
		return nil, fmt.Errorf("php scan: %w", err)
	}
	var mods []localModule
	sc := bufio.NewScanner(strings.NewReader(string(out)))
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		if line == "" {
			continue
		}
		parts := strings.SplitN(line, "|", 4)
		if len(parts) < 3 {
			continue
		}
		name := parts[1]
		if len(parts) == 4 {
			name = parts[3]
		}
		mods = append(mods, localModule{
			Dir:     parts[0],
			ID:      parts[1],
			Version: parts[2],
			Name:    name,
		})
	}
	sort.Slice(mods, func(i, j int) bool {
		return strings.ToLower(mods[i].Dir) < strings.ToLower(mods[j].Dir)
	})
	return mods, nil
}

type gitlabClient struct {
	base   string
	token  string
	client *http.Client
}

func newGitLab(cfg config) *gitlabClient {
	return &gitlabClient{
		base:  cfg.URL + "/api/v4",
		token: cfg.Token,
		client: &http.Client{
			Timeout: 30 * time.Second,
		},
	}
}

func (g *gitlabClient) get(path string, q url.Values) ([]byte, int, error) {
	u := g.base + path
	if len(q) > 0 {
		u += "?" + q.Encode()
	}
	req, err := http.NewRequest(http.MethodGet, u, nil)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("PRIVATE-TOKEN", g.token)
	req.Header.Set("Accept", "application/json")
	res, err := g.client.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer res.Body.Close()
	body, err := io.ReadAll(res.Body)
	if err != nil {
		return nil, res.StatusCode, err
	}
	return body, res.StatusCode, nil
}

func (g *gitlabClient) listModuleProjects(group, prefix string) ([]string, error) {
	var out []string
	page := 1
	for page <= 20 {
		q := url.Values{
			"per_page":           {"100"},
			"simple":             {"true"},
			"include_subgroups":  {"false"},
			"page":               {fmt.Sprintf("%d", page)},
		}
		body, code, err := g.get("/groups/"+url.PathEscape(group)+"/projects", q)
		if err != nil {
			return nil, err
		}
		if code < 200 || code >= 300 {
			return nil, fmt.Errorf("gitlab projects HTTP %d: %s", code, truncate(string(body), 200))
		}
		var rows []struct {
			Path string `json:"path"`
		}
		if err := json.Unmarshal(body, &rows); err != nil {
			return nil, err
		}
		if len(rows) == 0 {
			break
		}
		for _, r := range rows {
			if strings.HasPrefix(r.Path, prefix) {
				out = append(out, r.Path)
			}
		}
		if len(rows) < 100 {
			break
		}
		page++
	}
	sort.Strings(out)
	return out, nil
}

func (g *gitlabClient) latestTag(group, repo string) (string, error) {
	project := url.PathEscape(group + "/" + repo)
	q := url.Values{
		"per_page": {"5"},
		"order_by": {"version"},
		"sort":     {"desc"},
	}
	body, code, err := g.get("/projects/"+project+"/repository/tags", q)
	if err != nil {
		return "NONE", err
	}
	if code == 404 {
		return "NONE", nil
	}
	if code < 200 || code >= 300 {
		return "NONE", fmt.Errorf("HTTP %d", code)
	}
	var tags []struct {
		Name string `json:"name"`
	}
	if err := json.Unmarshal(body, &tags); err != nil {
		return "NONE", err
	}
	if len(tags) == 0 || tags[0].Name == "" {
		return "NONE", nil
	}
	return tags[0].Name, nil
}

func runScan(cfg config) scanResult {
	local, err := scanLocal(cfg.Root)
	if err != nil {
		return scanResult{Err: err}
	}
	gl := newGitLab(cfg)
	projects, err := gl.listModuleProjects(cfg.Group, cfg.Prefix)
	if err != nil {
		return scanResult{Local: local, Err: err}
	}

	localIDs := map[string]localModule{}
	for _, m := range local {
		localIDs[m.ID] = m
	}
	projectSet := map[string]bool{}
	for _, p := range projects {
		projectSet[p] = true
	}

	var missing []string
	for _, m := range local {
		want := cfg.Prefix + m.ID
		if !projectSet[want] {
			missing = append(missing, fmt.Sprintf("%s (local %s %s)", want, m.Dir, m.Version))
		}
	}

	var extra []string
	for _, p := range projects {
		id := strings.TrimPrefix(p, cfg.Prefix)
		if _, ok := localIDs[id]; !ok {
			extra = append(extra, p)
		}
	}

	type tagRes struct {
		idx    int
		latest string
	}
	rows := make([]compareRow, len(local))
	var wg sync.WaitGroup
	ch := make(chan tagRes, len(local))
	sem := make(chan struct{}, 8)

	for i, m := range local {
		wg.Add(1)
		go func(i int, m localModule) {
			defer wg.Done()
			sem <- struct{}{}
			defer func() { <-sem }()
			repo := cfg.Prefix + m.ID
			latest := "NONE"
			if projectSet[repo] {
				if t, err := gl.latestTag(cfg.Group, repo); err == nil {
					latest = t
				} else {
					latest = "NONE"
				}
			}
			ch <- tagRes{idx: i, latest: latest}
		}(i, m)
	}
	wg.Wait()
	close(ch)

	latestByIdx := map[int]string{}
	for r := range ch {
		latestByIdx[r.idx] = r.latest
	}

	for i, m := range local {
		repo := cfg.Prefix + m.ID
		latest := latestByIdx[i]
		status := "ok"
		switch {
		case !projectSet[repo] || latest == "NONE":
			status = "NO_TAGS"
			if !projectSet[repo] {
				status = "MISSING"
			}
		case latest != "v"+m.Version && latest != m.Version:
			status = "DIFF"
		}
		rows[i] = compareRow{
			Repo:   repo,
			Local:  m.Version,
			GitLab: latest,
			Status: status,
		}
	}

	return scanResult{
		Local:   local,
		GitLab:  projects,
		Missing: missing,
		Extra:   extra,
		Compare: rows,
	}
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

// —— TUI ——

type tab int

const (
	tabCompare tab = iota
	tabMissing
	tabExtra
	tabLocal
	tabGitLab
)

var tabNames = []string{"Compare", "Missing", "Extra", "Local", "GitLab"}

type model struct {
	cfg      config
	spin     spinner.Model
	vp       viewport.Model
	loading  bool
	result   scanResult
	tab      tab
	width    int
	height   int
	ready    bool
	errMsg   string
	scanned  time.Time
}

type scannedMsg scanResult

func initialModel(cfg config) model {
	s := spinner.New()
	s.Spinner = spinner.Dot
	s.Style = lipgloss.NewStyle().Foreground(lipgloss.Color("212"))
	return model{
		cfg:     cfg,
		spin:    s,
		loading: true,
	}
}

func (m model) Init() tea.Cmd {
	return tea.Batch(m.spin.Tick, doScan(m.cfg))
}

func doScan(cfg config) tea.Cmd {
	return func() tea.Msg {
		return scannedMsg(runScan(cfg))
	}
}

func (m model) Update(msg tea.Msg) (tea.Model, tea.Cmd) {
	switch msg := msg.(type) {
	case tea.KeyMsg:
		switch msg.String() {
		case "q", "ctrl+c":
			return m, tea.Quit
		case "r":
			if !m.loading {
				m.loading = true
				m.errMsg = ""
				return m, tea.Batch(m.spin.Tick, doScan(m.cfg))
			}
		case "tab", "right", "l":
			m.tab = (m.tab + 1) % tab(len(tabNames))
			m.refreshViewport()
		case "shift+tab", "left", "h":
			m.tab = (m.tab - 1 + tab(len(tabNames))) % tab(len(tabNames))
			m.refreshViewport()
		case "j", "down":
			m.vp.LineDown(1)
		case "k", "up":
			m.vp.LineUp(1)
		case "pgdown", "f":
			m.vp.HalfViewDown()
		case "pgup", "b":
			m.vp.HalfViewUp()
		case "g", "home":
			m.vp.GotoTop()
		case "G", "end":
			m.vp.GotoBottom()
		case "1", "2", "3", "4", "5":
			idx := int(msg.String()[0] - '1')
			if idx >= 0 && idx < len(tabNames) {
				m.tab = tab(idx)
				m.refreshViewport()
			}
		}
	case tea.WindowSizeMsg:
		m.width = msg.Width
		m.height = msg.Height
		header := 8
		footer := 2
		if !m.ready {
			m.vp = viewport.New(msg.Width-2, max(1, msg.Height-header-footer))
			m.ready = true
		} else {
			m.vp.Width = msg.Width - 2
			m.vp.Height = max(1, msg.Height-header-footer)
		}
		m.refreshViewport()
	case spinner.TickMsg:
		if m.loading {
			var cmd tea.Cmd
			m.spin, cmd = m.spin.Update(msg)
			return m, cmd
		}
	case scannedMsg:
		m.loading = false
		m.result = scanResult(msg)
		m.scanned = time.Now()
		if m.result.Err != nil {
			m.errMsg = m.result.Err.Error()
		}
		m.refreshViewport()
	}
	return m, nil
}

func (m *model) refreshViewport() {
	if !m.ready {
		return
	}
	m.vp.SetContent(m.tabContent())
	m.vp.GotoTop()
}

func (m model) tabContent() string {
	if m.loading {
		return m.spin.View() + "  Loading local modules + GitLab…"
	}
	if m.errMsg != "" && len(m.result.Compare) == 0 {
		return errorStyle.Render("Chyba: " + m.errMsg)
	}

	switch m.tab {
	case tabCompare:
		return m.renderCompare()
	case tabMissing:
		return m.renderList("MISSING ON GITLAB", m.result.Missing, "None — everything is on GitLab.")
	case tabExtra:
		return m.renderList("EXTRA ON GITLAB (no local)", m.result.Extra, "No extra projects.")
	case tabLocal:
		var lines []string
		for _, mod := range m.result.Local {
			lines = append(lines, fmt.Sprintf("%s|%s|%s|%s", mod.Dir, mod.ID, mod.Version, mod.Name))
		}
		return m.renderList("LOCAL MODULES", lines, "(empty)")
	case tabGitLab:
		return m.renderList(fmt.Sprintf("GITLAB PROJECTS (group=%s, prefix=%s)", m.cfg.Group, m.cfg.Prefix), m.result.GitLab, "(empty)")
	}
	return ""
}

func (m model) renderCompare() string {
	var b strings.Builder
	b.WriteString(sectionStyle.Render("VERSION COMPARE (latest tag vs local)"))
	b.WriteString("\n\n")

	okN, diffN, missN := 0, 0, 0
	for _, r := range m.result.Compare {
		switch r.Status {
		case "ok":
			okN++
		case "DIFF":
			diffN++
		default:
			missN++
		}
	}
	b.WriteString(fmt.Sprintf("ok=%d  DIFF=%d  NO_TAGS/MISSING=%d\n\n", okN, diffN, missN))

	for _, r := range m.result.Compare {
		line := fmt.Sprintf("%-28s local=%-8s gitlab=%-10s %s", r.Repo, r.Local, r.GitLab, r.Status)
		switch r.Status {
		case "ok":
			b.WriteString(okStyle.Render(line))
		case "DIFF":
			b.WriteString(diffStyle.Render(line))
		default:
			b.WriteString(badStyle.Render(line))
		}
		b.WriteByte('\n')
	}
	return b.String()
}

func (m model) renderList(title string, items []string, empty string) string {
	var b strings.Builder
	b.WriteString(sectionStyle.Render(title))
	b.WriteString(fmt.Sprintf("  (%d)\n\n", len(items)))
	if len(items) == 0 {
		b.WriteString(mutedStyle.Render(empty))
		return b.String()
	}
	for _, it := range items {
		b.WriteString(it)
		b.WriteByte('\n')
	}
	return b.String()
}

func (m model) View() string {
	title := titleStyle.Render("GEREQ modules ↔ GitLab")
	meta := mutedStyle.Render(fmt.Sprintf("%s/%s*  ·  %s", m.cfg.Group, m.cfg.Prefix, m.cfg.Root))

	var tabs []string
	for i, name := range tabNames {
		if tab(i) == m.tab {
			tabs = append(tabs, activeTab.Render(fmt.Sprintf(" %d:%s ", i+1, name)))
		} else {
			tabs = append(tabs, inactiveTab.Render(fmt.Sprintf(" %d:%s ", i+1, name)))
		}
	}
	tabRow := lipgloss.JoinHorizontal(lipgloss.Top, tabs...)

	status := ""
	if m.loading {
		status = m.spin.View() + " scanning…"
	} else if !m.scanned.IsZero() {
		status = mutedStyle.Render("updated " + m.scanned.Format("15:04:05") + "  ·  r refresh  ·  q quit  ·  ←/→ tabs")
	}
	if m.errMsg != "" && len(m.result.Compare) > 0 {
		status += "\n" + errorStyle.Render("warn: "+m.errMsg)
	}

	body := m.vp.View()
	if !m.ready {
		body = m.tabContent()
	}

	return lipgloss.JoinVertical(lipgloss.Left,
		title,
		meta,
		tabRow,
		"",
		body,
		"",
		status,
	)
}

var (
	titleStyle   = lipgloss.NewStyle().Bold(true).Foreground(lipgloss.Color("15")).MarginBottom(0)
	sectionStyle = lipgloss.NewStyle().Bold(true).Foreground(lipgloss.Color("39"))
	mutedStyle   = lipgloss.NewStyle().Foreground(lipgloss.Color("245"))
	errorStyle   = lipgloss.NewStyle().Foreground(lipgloss.Color("196"))
	okStyle      = lipgloss.NewStyle().Foreground(lipgloss.Color("78"))
	diffStyle    = lipgloss.NewStyle().Foreground(lipgloss.Color("214"))
	badStyle     = lipgloss.NewStyle().Foreground(lipgloss.Color("203"))
	activeTab    = lipgloss.NewStyle().Bold(true).Foreground(lipgloss.Color("230")).Background(lipgloss.Color("63")).Padding(0, 1)
	inactiveTab  = lipgloss.NewStyle().Foreground(lipgloss.Color("246")).Padding(0, 1)
)

func max(a, b int) int {
	if a > b {
		return a
	}
	return b
}

func printPlain(cfg config, res scanResult) {
	fmt.Println("=== LOCAL MODULES ===")
	for _, m := range res.Local {
		fmt.Printf("%s|%s|%s|%s\n", m.Dir, m.ID, m.Version, m.Name)
	}
	fmt.Println()
	fmt.Printf("=== GITLAB PROJECTS (group=%s, prefix=%s) ===\n", cfg.Group, cfg.Prefix)
	for _, p := range res.GitLab {
		fmt.Println(p)
	}
	fmt.Println()
	fmt.Println("=== MISSING ON GITLAB ===")
	if len(res.Missing) == 0 {
		fmt.Println("(none)")
	} else {
		for _, m := range res.Missing {
			fmt.Println("MISSING:", m)
		}
	}
	fmt.Println()
	fmt.Println("=== EXTRA ON GITLAB (no local) ===")
	if len(res.Extra) == 0 {
		fmt.Println("(none)")
	} else {
		for _, e := range res.Extra {
			fmt.Println("EXTRA:", e)
		}
	}
	fmt.Println()
	fmt.Println("=== VERSION COMPARE (latest tag vs local) ===")
	for _, r := range res.Compare {
		fmt.Printf("%-28s local=%-8s gitlab=%-10s %s\n", r.Repo, r.Local, r.GitLab, r.Status)
	}
}

func main() {
	plain := false
	args := os.Args[1:]
	for _, a := range args {
		switch a {
		case "-plain", "--plain":
			plain = true
		case "-h", "--help":
			fmt.Println(`gereq-modules — compare local modules with GitLab

Usage:
  gereq-modules          interactive TUI
  gereq-modules --plain  text output (like the shell script)

Env (.env in project root):
  GITLAB_URL, GITLAB_TOKEN, GITLAB_GROUP, MODULE_REPO_PREFIX
  GEREQ_ROOT  (optional absolute path to the project)

TUI keys: 1–5 / ←→ tabs · r refresh · q quit`)
			return
		}
	}

	cfg, err := loadConfig()
	if err != nil {
		fmt.Fprintf(os.Stderr, "gereq-modules: %v\n", err)
		os.Exit(1)
	}
	if err := os.Chdir(cfg.Root); err != nil {
		fmt.Fprintf(os.Stderr, "gereq-modules: chdir: %v\n", err)
		os.Exit(1)
	}

	if plain {
		res := runScan(cfg)
		if res.Err != nil {
			fmt.Fprintf(os.Stderr, "gereq-modules: %v\n", res.Err)
			os.Exit(1)
		}
		printPlain(cfg, res)
		return
	}

	p := tea.NewProgram(initialModel(cfg), tea.WithAltScreen())
	if _, err := p.Run(); err != nil {
		fmt.Fprintf(os.Stderr, "gereq-modules: %v\n", err)
		os.Exit(1)
	}
}
