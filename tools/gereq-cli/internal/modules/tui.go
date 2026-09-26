package modules

import (
	"fmt"
	"time"

	"github.com/charmbracelet/bubbles/spinner"
	"github.com/charmbracelet/bubbles/viewport"
	tea "github.com/charmbracelet/bubbletea"
	"github.com/charmbracelet/lipgloss"
	"gereq.dev/gereq-cli/internal/gitlab"
)

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
	root     string
	cfg      gitlab.Config
	spin     spinner.Model
	vp       viewport.Model
	loading  bool
	result   ScanResult
	tab      tab
	width    int
	height   int
	ready    bool
	errMsg   string
	scanned  time.Time
}

type scannedMsg ScanResult

func RunTUI(root string) error {
	cfg := gitlab.LoadConfig(root)
	p := tea.NewProgram(initialModel(root, cfg), tea.WithAltScreen())
	_, err := p.Run()
	return err
}

func initialModel(root string, cfg gitlab.Config) model {
	s := spinner.New()
	s.Spinner = spinner.Dot
	s.Style = lipgloss.NewStyle().Foreground(lipgloss.Color("212"))
	return model{root: root, cfg: cfg, spin: s, loading: true}
}

func (m model) Init() tea.Cmd {
	return tea.Batch(m.spin.Tick, doScan(m.root))
}

func doScan(root string) tea.Cmd {
	return func() tea.Msg {
		return scannedMsg(RunScan(root))
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
				return m, tea.Batch(m.spin.Tick, doScan(m.root))
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
		header, footer := 8, 2
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
		m.result = ScanResult(msg)
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
		return errorStyle.Render("Error: " + m.errMsg)
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
	var b string
	b += sectionStyle.Render("VERSION COMPARE (latest tag vs local)") + "\n\n"
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
	b += fmt.Sprintf("ok=%d  DIFF=%d  NO_TAGS/MISSING=%d\n\n", okN, diffN, missN)
	for _, r := range m.result.Compare {
		line := fmt.Sprintf("%-28s local=%-8s gitlab=%-10s %s", r.Repo, r.Local, r.GitLab, r.Status)
		switch r.Status {
		case "ok":
			b += okStyle.Render(line)
		case "DIFF":
			b += diffStyle.Render(line)
		default:
			b += badStyle.Render(line)
		}
		b += "\n"
	}
	return b
}

func (m model) renderList(title string, items []string, empty string) string {
	b := sectionStyle.Render(title) + fmt.Sprintf("  (%d)\n\n", len(items))
	if len(items) == 0 {
		return b + mutedStyle.Render(empty)
	}
	for _, it := range items {
		b += it + "\n"
	}
	return b
}

func (m model) View() string {
	title := titleStyle.Render("GEREQ modules ↔ GitLab")
	meta := mutedStyle.Render(fmt.Sprintf("%s/%s*  ·  %s", m.cfg.Group, m.cfg.Prefix, m.root))
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
	return lipgloss.JoinVertical(lipgloss.Left, title, meta, tabRow, "", body, "", status)
}

var (
	titleStyle   = lipgloss.NewStyle().Bold(true).Foreground(lipgloss.Color("15"))
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
