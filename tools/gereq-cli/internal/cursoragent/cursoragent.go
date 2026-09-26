// Package cursoragent drives the Cursor CLI (`agent`) from the grq binary.
// Auth is owned by the user (agent login or CURSOR_API_KEY) — not bundled.
package cursoragent

import (
	"bytes"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"strings"
)

// DefaultModel is the cheap Composer 2.5 standard variant.
const DefaultModel = "composer-2.5[fast=false]"

// FastModel is Composer 2.5 fast (higher priced).
const FastModel = "composer-2.5[fast=true]"

// Available reports whether the Cursor agent CLI is on PATH.
func Available() bool {
	return binary() != ""
}

func binary() string {
	for _, name := range []string{"agent", "cursor-agent"} {
		if p, err := exec.LookPath(name); err == nil {
			return p
		}
	}
	return ""
}

// Model returns the composer-2.5 selection for cheap (default) or fast.
func Model(fast bool) string {
	if fast {
		return FastModel
	}
	return DefaultModel
}

// Ask runs a read-only one-shot prompt and returns the assistant text result.
func Ask(cwd, prompt, model string) (string, error) {
	return run(cwd, prompt, model, true, false)
}

// Edit runs a one-shot agent with write tools enabled (--force) and returns the result text.
func Edit(cwd, prompt, model string) (string, error) {
	return run(cwd, prompt, model, false, true)
}

func run(cwd, prompt, model string, askOnly, force bool) (string, error) {
	bin := binary()
	if bin == "" {
		return "", fmt.Errorf("Cursor CLI `agent` not found on PATH (install: https://cursor.com/install)")
	}
	if model == "" {
		model = DefaultModel
	}
	args := []string{
		"-p",
		"--output-format", "json",
		"--trust",
		"--workspace", cwd,
		"--model", model,
	}
	if askOnly {
		args = append(args, "--mode", "ask")
	}
	if force {
		args = append(args, "--force")
	}
	if key := strings.TrimSpace(os.Getenv("CURSOR_API_KEY")); key != "" {
		args = append(args, "--api-key", key)
	}
	args = append(args, prompt)

	cmd := exec.Command(bin, args...)
	cmd.Dir = cwd
	cmd.Env = os.Environ()
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		msg := strings.TrimSpace(stderr.String())
		if msg == "" {
			msg = strings.TrimSpace(stdout.String())
		}
		if msg == "" {
			msg = err.Error()
		}
		return "", fmt.Errorf("agent: %s", truncate(msg, 400))
	}
	return parsePrintJSON(stdout.Bytes())
}

type printEnvelope struct {
	Type    string `json:"type"`
	Subtype string `json:"subtype"`
	IsError bool   `json:"is_error"`
	Result  string `json:"result"`
}

func parsePrintJSON(raw []byte) (string, error) {
	raw = bytes.TrimSpace(raw)
	if len(raw) == 0 {
		return "", fmt.Errorf("agent returned empty output")
	}
	// Prefer last JSON object line (CLI may print noise before the envelope).
	lines := bytes.Split(raw, []byte("\n"))
	var last []byte
	for i := len(lines) - 1; i >= 0; i-- {
		line := bytes.TrimSpace(lines[i])
		if len(line) > 0 && line[0] == '{' {
			last = line
			break
		}
	}
	if last == nil {
		last = raw
	}
	var env printEnvelope
	if err := json.Unmarshal(last, &env); err != nil {
		// Fallback: treat whole stdout as plain text result
		return string(raw), nil
	}
	if env.IsError || (env.Type == "result" && env.Subtype != "" && env.Subtype != "success") {
		return "", fmt.Errorf("agent error: %s", truncate(env.Result, 300))
	}
	if strings.TrimSpace(env.Result) == "" {
		return "", fmt.Errorf("agent returned empty result")
	}
	return env.Result, nil
}

// ExtractJSONObject finds the first {...} blob in text (agents often wrap JSON in markdown).
func ExtractJSONObject(text string) (json.RawMessage, error) {
	text = strings.TrimSpace(text)
	if text == "" {
		return nil, fmt.Errorf("empty text")
	}
	if strings.HasPrefix(text, "{") {
		return json.RawMessage(text), nil
	}
	start := strings.Index(text, "{")
	end := strings.LastIndex(text, "}")
	if start < 0 || end <= start {
		return nil, fmt.Errorf("no JSON object in agent output")
	}
	return json.RawMessage(text[start : end+1]), nil
}

func truncate(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return s[:n] + "…"
}
