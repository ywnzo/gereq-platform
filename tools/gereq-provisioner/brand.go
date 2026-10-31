package main

import (
	"context"
	_ "embed"
	"strings"
	"unicode"
)

// Embed the initializer so approved existing images can receive bootstrap names without image rebuilds.
//
//go:embed runtime/brand.php
var brandInitializer string

func validBusinessName(name string) bool {
	if !plain(name, 100) || strings.TrimSpace(name) != name {
		return false
	}
	for _, r := range name {
		if unicode.IsControl(r) {
			return false
		}
	}
	return true
}
func (e Engine) initializeBrand(ctx context.Context, s *State, p string) error {
	if s.Request.BrandName == "" {
		return nil
	}
	if err := e.checkOwner(ctx, s, p); err != nil {
		return err
	}
	code := strings.TrimPrefix(brandInitializer, "<?php")
	_, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "-r", code, s.Request.BrandName, s.Request.SiteID, "initialize"))
	return err
}

//go:embed runtime/administrator.php
var administratorInitializer string

func (e Engine) initializeAdministrator(ctx context.Context, s *State, p string) error {
	code := strings.TrimPrefix(administratorInitializer, "<?php")
	_, err := e.Runner.Run(ctx, compose(s, p, "run", "--rm", "--no-deps", "app", "php", "-r", code))
	return err
}
