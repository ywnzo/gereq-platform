package main

import (
	"errors"
	"regexp"
	"sort"
)

var moduleIDPattern = regexp.MustCompile(`^[a-z][a-z0-9_]{1,63}$`)

func instanceModules(s *State) []string {
	if len(s.ActiveModules) > 0 {
		return append([]string(nil), s.ActiveModules...)
	}
	if len(s.Request.EnabledModules) > 0 {
		return append([]string(nil), s.Request.EnabledModules...)
	}
	return append([]string(nil), s.Release.Modules...)
}
func sameModules(a, b []string) bool {
	x := append([]string(nil), a...)
	y := append([]string(nil), b...)
	sort.Strings(x)
	sort.Strings(y)
	return registryHash(x) == registryHash(y)
}
func validateSelectedModules(r Request, l Release) error {
	available := map[string]bool{}
	for _, id := range l.Modules {
		if !moduleIDPattern.MatchString(id) || available[id] {
			return errors.New("invalid release module set")
		}
		available[id] = true
	}
	if !available["cms"] {
		return errors.New("website release requires cms")
	}
	selected := instanceModules(&State{Request: r, Release: l})
	seen := map[string]bool{}
	for _, id := range selected {
		if !available[id] || seen[id] {
			return errors.New("selected module is missing from release or duplicated")
		}
		seen[id] = true
	}
	if !seen["cms"] {
		return errors.New("website package requires cms")
	}
	for _, id := range selected {
		for _, dep := range l.ModuleRequires[id] {
			if !seen[dep] {
				return errors.New("selected module has a missing package dependency: " + id + " requires " + dep)
			}
		}
	}
	if !sameModules(selected, l.Modules) && !l.ModuleSelection {
		return errors.New("selected release does not support package module selection")
	}
	return nil
}
