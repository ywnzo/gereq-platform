package env

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

type VarMeta struct {
	Type        string
	Values      []string
	Default     string
	Secret      bool
	Required    bool
	Description string
}

type Schema struct {
	// Order preserves TOML table order.
	Keys []string
	Vars map[string]VarMeta
}

var (
	tableRe = regexp.MustCompile(`^\[([A-Z][A-Z0-9_]*)\]$`)
	kvRe    = regexp.MustCompile(`^([a-z_]+)\s*=\s*(.+)$`)
	strRe   = regexp.MustCompile(`"((?:\\.|[^"\\])*)"|'((?:\\.|[^'\\])*)'`)
)

func LoadSchema(path string) (*Schema, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}
	s := &Schema{Vars: map[string]VarMeta{}}
	var current string
	for _, line := range strings.Split(string(raw), "\n") {
		trim := strings.TrimSpace(line)
		if trim == "" || strings.HasPrefix(trim, "#") {
			continue
		}
		if m := tableRe.FindStringSubmatch(trim); m != nil {
			current = m[1]
			s.Keys = append(s.Keys, current)
			s.Vars[current] = VarMeta{Type: "string"}
			continue
		}
		if current == "" {
			continue
		}
		m := kvRe.FindStringSubmatch(trim)
		if m == nil {
			continue
		}
		meta := s.Vars[current]
		field, rawVal := m[1], strings.TrimSpace(m[2])
		switch field {
		case "type", "description":
			if field == "type" {
				meta.Type = unquote(rawVal)
			} else {
				meta.Description = unquote(rawVal)
			}
		case "default":
			meta.Default = parseDefault(rawVal)
		case "secret":
			meta.Secret = parseBool(rawVal)
		case "required":
			meta.Required = parseBool(rawVal)
		case "values":
			meta.Values = parseValues(rawVal)
		}
		s.Vars[current] = meta
	}
	return s, nil
}

func unquote(raw string) string {
	raw = strings.TrimSpace(raw)
	if len(raw) >= 2 {
		if (raw[0] == '"' && raw[len(raw)-1] == '"') || (raw[0] == '\'' && raw[len(raw)-1] == '\'') {
			inner := raw[1 : len(raw)-1]
			inner = strings.ReplaceAll(inner, `\\`, `\`)
			inner = strings.ReplaceAll(inner, `\"`, `"`)
			inner = strings.ReplaceAll(inner, `\'`, `'`)
			inner = strings.ReplaceAll(inner, `\n`, "\n")
			return inner
		}
	}
	return raw
}

func parseDefault(raw string) string {
	raw = strings.TrimSpace(raw)
	if raw == "true" || raw == "false" || regexp.MustCompile(`^-?\d+$`).MatchString(raw) {
		return raw
	}
	if raw == `""` || raw == `''` {
		return ""
	}
	return unquote(raw)
}

func parseBool(raw string) bool {
	v := strings.ToLower(unquote(raw))
	return v == "true" || v == "1" || v == "yes"
}

func parseValues(raw string) []string {
	raw = strings.TrimSpace(raw)
	if !strings.HasPrefix(raw, "[") || !strings.HasSuffix(raw, "]") {
		return nil
	}
	inner := strings.TrimSpace(raw[1 : len(raw)-1])
	if inner == "" {
		return nil
	}
	var out []string
	for _, m := range strRe.FindAllStringSubmatch(inner, -1) {
		if m[1] != "" || (len(m[0]) >= 2 && m[0][0] == '"') {
			out = append(out, m[1])
		} else {
			out = append(out, m[2])
		}
	}
	return out
}

type InitOptions struct {
	Docker          bool
	Force           bool
	Port            string
	GenerateSecrets bool
}

func Init(root string, opt InitOptions) error {
	schemaPath := filepath.Join(root, "config", "env.schema.toml")
	envPath := filepath.Join(root, ".env")
	if _, err := os.Stat(envPath); err == nil && !opt.Force {
		fmt.Println("✓ .env already exists")
		return nil
	}
	schema, err := LoadSchema(schemaPath)
	if err != nil {
		return err
	}
	overrides := map[string]string{}
	if opt.Docker {
		port := opt.Port
		if port == "" {
			port = "8080"
		}
		overrides = map[string]string{
			"APP_DEBUG":       "true",
			"APP_URL":         "http://127.0.0.1:" + port,
			"DB_CONNECTION":   "pgsql",
			"DB_HOST":         "postgres",
			"DB_PORT":         "5432",
			"REDIS_HOST":      "redis",
			"SESSION_DRIVER":  "redis",
			"QUEUE_DRIVER":    "redis",
			"CACHE_DRIVER":    "redis",
		}
	}
	content := schema.Render(overrides, opt.GenerateSecrets)
	if err := os.WriteFile(envPath, []byte(content), 0o644); err != nil {
		return err
	}
	if err := EnsureStorage(root); err != nil {
		return err
	}
	suffix := ""
	if opt.Docker {
		suffix = " (docker profile)"
	}
	fmt.Printf("✓ .env written from env.schema.toml%s\n", suffix)
	return nil
}

func (s *Schema) Render(overrides map[string]string, generateSecrets bool) string {
	var b strings.Builder
	b.WriteString("# GEREQ Platform — generated from config/env.schema.toml\n")
	b.WriteString("# Do not commit real secrets. Regenerate: grq env init\n\n")
	for _, key := range s.Keys {
		meta := s.Vars[key]
		value, ok := overrides[key]
		if !ok {
			value = meta.Default
		}
		if generateSecrets && value == "" {
			if key == "APP_KEY" {
				value = randomHex(32)
			} else if key == "DB_PASSWORD" {
				value = randomPassword(32)
			}
		}
		b.WriteString(formatLine(key, value))
		b.WriteByte('\n')
	}
	return b.String()
}

func formatLine(key, value string) string {
	if value == "" || regexp.MustCompile(`[\s#"\\']`).MatchString(value) {
		esc := strings.ReplaceAll(value, `\`, `\\`)
		esc = strings.ReplaceAll(esc, `"`, `\"`)
		esc = strings.ReplaceAll(esc, "\n", `\n`)
		return key + `="` + esc + `"`
	}
	return key + "=" + value
}

func randomHex(n int) string {
	buf := make([]byte, n)
	_, _ = rand.Read(buf)
	return hex.EncodeToString(buf)
}

func randomPassword(n int) string {
	const alphabet = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789"
	buf := make([]byte, n)
	_, _ = rand.Read(buf)
	out := make([]byte, n)
	for i := 0; i < n; i++ {
		out[i] = alphabet[int(buf[i])%len(alphabet)]
	}
	return string(out)
}

func EnsureStorage(root string) error {
	dirs := []string{
		"cache", "logs", "sessions", "tmp/queue", "uploads", "invoices", "database",
	}
	for _, d := range dirs {
		if err := os.MkdirAll(filepath.Join(root, "storage", d), 0o775); err != nil {
			return err
		}
	}
	return nil
}

func Validate(root string) []string {
	schema, err := LoadSchema(filepath.Join(root, "config", "env.schema.toml"))
	if err != nil {
		return []string{err.Error()}
	}
	envPath := filepath.Join(root, ".env")
	raw, err := os.ReadFile(envPath)
	if err != nil {
		return []string{"Missing .env: " + envPath}
	}
	parsed := parseEnvFile(string(raw))
	var problems []string
	for _, key := range schema.Keys {
		meta := schema.Vars[key]
		rawVal, present := parsed[key]
		if meta.Required && (!present || rawVal == "") {
			problems = append(problems, "Required "+key+" is missing or empty")
			continue
		}
		if !present || rawVal == "" {
			continue
		}
		problems = append(problems, validateValue(key, rawVal, meta)...)
	}
	for k := range parsed {
		if _, ok := schema.Vars[k]; !ok {
			problems = append(problems, "Unknown key "+k+" (not in env.schema.toml)")
		}
	}
	return problems
}

func parseEnvFile(contents string) map[string]string {
	out := map[string]string{}
	for _, line := range strings.Split(contents, "\n") {
		trim := strings.TrimSpace(line)
		if trim == "" || strings.HasPrefix(trim, "#") || !strings.Contains(trim, "=") {
			continue
		}
		i := strings.Index(trim, "=")
		key := strings.TrimSpace(trim[:i])
		if !regexp.MustCompile(`^[A-Z][A-Z0-9_]*$`).MatchString(key) {
			continue
		}
		val := strings.TrimSpace(trim[i+1:])
		val = unquote(val)
		out[key] = val
	}
	return out
}

func validateValue(key, raw string, meta VarMeta) []string {
	var problems []string
	switch meta.Type {
	case "bool":
		low := strings.ToLower(raw)
		ok := false
		for _, v := range []string{"true", "false", "1", "0", "(true)", "(false)"} {
			if low == v {
				ok = true
				break
			}
		}
		if !ok {
			problems = append(problems, key+" must be bool, got: "+raw)
		}
	case "int":
		if !regexp.MustCompile(`^-?\d+$`).MatchString(raw) {
			problems = append(problems, key+" must be int, got: "+raw)
		}
	case "enum":
		if len(meta.Values) > 0 {
			found := false
			for _, v := range meta.Values {
				if v == raw {
					found = true
					break
				}
			}
			if !found {
				problems = append(problems, fmt.Sprintf("%s must be one of [%s], got: %s", key, strings.Join(meta.Values, ", "), raw))
			}
		}
	}
	return problems
}
