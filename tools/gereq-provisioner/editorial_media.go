package main

import (
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"regexp"
)

//go:embed examples/editorial/editorial-path.png
var editorialIllustration []byte

// ensureEditorialMedia provisions the bundled illustration in the target instance's media library.
func (e Engine) ensureEditorialMedia(ctx context.Context, site string) (string, error) {
	unlock, err := e.lock()
	if err != nil {
		return "", err
	}
	defer unlock()
	state, instance, err := e.load(site)
	if err != nil {
		return "", err
	}
	if state.Status != "ready" && state.Status != "needs_customer_setup" {
		return "", errors.New("instance must be running")
	}
	if err = e.checkOwner(ctx, state, instance); err != nil {
		return "", err
	}
	file, err := os.CreateTemp(filepath.Join(instance, "storage"), ".editorial-upload-")
	if err != nil {
		return "", err
	}
	defer os.Remove(file.Name())
	if err = file.Chmod(0600); err == nil {
		_, err = file.Write(editorialIllustration)
	}
	if err == nil {
		err = file.Sync()
	}
	if closeErr := file.Close(); err == nil {
		err = closeErr
	}
	if err != nil {
		return "", err
	}
	result, err := e.Runner.Run(ctx, compose(state, instance, "exec", "-T", "app", "php", "/opt/provision/bridge.php", "editorial-media", filepath.Base(file.Name())))
	if err != nil {
		return "", err
	}
	var response struct {
		OK  bool   `json:"ok"`
		URL string `json:"url"`
	}
	if json.Unmarshal(result, &response) != nil || !response.OK || !regexp.MustCompile(`^/media/[a-f0-9-]{36}/file$`).MatchString(response.URL) {
		return "", errors.New("invalid editorial media response")
	}
	return response.URL, nil
}
