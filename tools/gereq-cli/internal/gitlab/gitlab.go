package gitlab

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/joho/godotenv"
)

type Config struct {
	URL    string
	Token  string
	Group  string
	Prefix string
}

func LoadConfig(root string) Config {
	_ = godotenv.Load(filepath.Join(root, ".env"))
	return Config{
		URL:    strings.TrimRight(envOr("GITLAB_URL", "https://git.gereq.cz"), "/"),
		Token:  strings.TrimSpace(os.Getenv("GITLAB_TOKEN")),
		Group:  envOr("GITLAB_GROUP", "gereq"),
		Prefix: envOr("MODULE_REPO_PREFIX", "module-"),
	}
}

func envOr(k, def string) string {
	if v := strings.TrimSpace(os.Getenv(k)); v != "" {
		return v
	}
	return def
}

type Client struct {
	cfg    Config
	client *http.Client
}

func New(cfg Config) *Client {
	return &Client{cfg: cfg, client: &http.Client{Timeout: 30 * time.Second}}
}

func (c *Client) Config() Config { return c.cfg }

func (c *Client) request(method, path string, body any) ([]byte, int, error) {
	var rdr io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return nil, 0, err
		}
		rdr = bytes.NewReader(b)
	}
	req, err := http.NewRequest(method, c.cfg.URL+"/api/v4"+path, rdr)
	if err != nil {
		return nil, 0, err
	}
	req.Header.Set("PRIVATE-TOKEN", c.cfg.Token)
	req.Header.Set("Accept", "application/json")
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	res, err := c.client.Do(req)
	if err != nil {
		return nil, 0, err
	}
	defer res.Body.Close()
	data, err := io.ReadAll(res.Body)
	return data, res.StatusCode, err
}

func (c *Client) ListModuleProjects() ([]string, error) {
	var out []string
	page := 1
	for page <= 20 {
		q := url.Values{"per_page": {"100"}, "simple": {"true"}, "page": {fmt.Sprintf("%d", page)}}
		body, code, err := c.request(http.MethodGet, "/groups/"+url.PathEscape(c.cfg.Group)+"/projects?"+q.Encode(), nil)
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
			if strings.HasPrefix(r.Path, c.cfg.Prefix) {
				out = append(out, r.Path)
			}
		}
		if len(rows) < 100 {
			break
		}
		page++
	}
	return out, nil
}

func (c *Client) LatestTag(repo string) (string, error) {
	project := url.PathEscape(c.cfg.Group + "/" + repo)
	q := url.Values{"per_page": {"5"}, "order_by": {"version"}, "sort": {"desc"}}
	body, code, err := c.request(http.MethodGet, "/projects/"+project+"/repository/tags?"+q.Encode(), nil)
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

func (c *Client) EnsureProject(path, description string) error {
	if c.cfg.Token == "" {
		return fmt.Errorf("GITLAB_TOKEN required")
	}
	full := c.cfg.Group + "/" + path
	enc := url.PathEscape(full)
	_, code, err := c.request(http.MethodGet, "/projects/"+enc, nil)
	if err != nil {
		return err
	}
	if code >= 200 && code < 300 {
		return nil
	}
	// resolve group id
	gbody, gcode, err := c.request(http.MethodGet, "/groups/"+url.PathEscape(c.cfg.Group), nil)
	if err != nil {
		return err
	}
	if gcode < 200 || gcode >= 300 {
		return fmt.Errorf("group lookup HTTP %d", gcode)
	}
	var group struct {
		ID int `json:"id"`
	}
	if err := json.Unmarshal(gbody, &group); err != nil {
		return err
	}
	payload := map[string]any{
		"name":                 path,
		"path":                 path,
		"namespace_id":         group.ID,
		"description":          description,
		"visibility":           "private",
		"initialize_with_readme": false,
	}
	body, code, err := c.request(http.MethodPost, "/projects", payload)
	if err != nil {
		return err
	}
	if code < 200 || code >= 300 {
		return fmt.Errorf("create project HTTP %d: %s", code, truncate(string(body), 300))
	}
	return nil
}

func (c *Client) SyncBadges(repo, mid, version string) error {
	if c.cfg.Token == "" {
		return fmt.Errorf("GITLAB_TOKEN required")
	}
	full := repo
	if !strings.Contains(repo, "/") {
		full = c.cfg.Group + "/" + repo
	}
	enc := url.PathEscape(full)
	body, code, err := c.request(http.MethodGet, "/projects/"+enc+"/badges", nil)
	if err != nil {
		return err
	}
	if code == 200 {
		var existing []struct {
			ID int `json:"id"`
		}
		_ = json.Unmarshal(body, &existing)
		for _, b := range existing {
			_, _, _ = c.request(http.MethodDelete, fmt.Sprintf("/projects/%s/badges/%d", enc, b.ID), nil)
		}
	}
	projectURL := c.cfg.URL + "/" + full
	ver := version
	if !strings.HasPrefix(ver, "v") {
		ver = "v" + ver
	}
	badges := []map[string]string{
		{"name": "GEREQ", "link_url": c.cfg.URL + "/" + c.cfg.Group, "image_url": shields("GEREQ", "module", "1a3020")},
		{"name": "Version", "link_url": projectURL + "/-/tags", "image_url": shields("version", ver, "3dd6c6")},
		{"name": "Module", "link_url": projectURL, "image_url": shields("module", mid, "6eb6ff")},
		{"name": "Visibility", "link_url": projectURL, "image_url": shields("visibility", "private", "f08a6a")},
	}
	for _, b := range badges {
		_, code, err := c.request(http.MethodPost, "/projects/"+enc+"/badges", b)
		if err != nil {
			return err
		}
		if code < 200 || code >= 300 {
			return fmt.Errorf("badge %s HTTP %d", b["name"], code)
		}
	}
	return nil
}

func shields(label, message, color string) string {
	enc := url.PathEscape(label + "-" + message + "-" + color)
	return "https://img.shields.io/badge/" + enc + "?style=flat-square"
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n]
}

func PatchREADME(moduleDir, mid, name, version, repo string) error {
	cfgURL := envOr("GITLAB_URL", "https://git.gereq.cz")
	projectURL := strings.TrimRight(cfgURL, "/") + "/" + repo
	ver := version
	if !strings.HasPrefix(ver, "v") {
		ver = "v" + ver
	}
	lines := []string{
		fmt.Sprintf("[![GEREQ](%s)](%s)", shields("GEREQ", "module", "1a3020"), strings.TrimRight(cfgURL, "/")+"/gereq"),
		fmt.Sprintf("[![Version](%s)](%s/-/tags)", shields("version", ver, "3dd6c6"), projectURL),
		fmt.Sprintf("[![Module](%s)](%s)", shields("module", mid, "6eb6ff"), projectURL),
		fmt.Sprintf("[![Visibility](%s)](%s)", shields("visibility", "private", "f08a6a"), projectURL),
	}
	block := strings.Join(lines, " ") + "\n\n"
	readme := filepath.Join(moduleDir, "README.md")
	var newContent string
	if data, err := os.ReadFile(readme); err == nil {
		text := string(data)
		if strings.HasPrefix(text, "#") {
			parts := strings.SplitN(text, "\n", 2)
			rest := ""
			if len(parts) > 1 {
				rest = strings.TrimLeft(parts[1], "\n")
			}
			newContent = parts[0] + "\n\n" + block + rest
		} else {
			newContent = block + text
		}
	} else {
		newContent = fmt.Sprintf("# %s\n\n%sGEREQ module `%s` — v%s\n", name, block, mid, version)
	}
	return os.WriteFile(readme, []byte(newContent), 0o644)
}
