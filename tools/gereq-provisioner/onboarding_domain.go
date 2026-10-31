package main

import (
	"errors"
	"strings"
	"unicode"
)

var errOnboardingDomainTaken = errors.New("domain already reserved")

func domainFromName(name string) string {
	var result strings.Builder
	separator := false
	replacements := map[rune]string{}
	for plain, letters := range map[string]string{"a": "àáâãäåāăą", "c": "çćčĉċ", "d": "ďđ", "e": "èéêëēėęěĕ", "g": "ğĝġģ", "i": "ìíîïīįıĭ", "l": "łĺľļ", "n": "ñńňņ", "o": "òóôõöøōőŏ", "r": "řŕŗ", "s": "śšşŝș", "t": "ťţț", "u": "ùúûüūůűŭų", "y": "ýÿŷ", "z": "žźż", "ss": "ß"} {
		for _, letter := range letters {
			replacements[letter] = plain
		}
	}
	for _, r := range strings.ToLower(strings.TrimSpace(name)) {
		if unicode.IsMark(r) {
			continue
		}
		value := ""
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			value = string(r)
		} else {
			value = replacements[r]
		}
		if value == "" {
			separator = result.Len() > 0
			continue
		}
		if separator {
			result.WriteByte('-')
			separator = false
		}
		result.WriteString(value)
	}
	slug := result.String()
	if slug == "" {
		return ""
	}
	if slug[0] >= '0' && slug[0] <= '9' {
		slug = "web-" + slug
	}
	if len(slug) < 3 {
		slug += "-web"
	}
	if len(slug) > 39 {
		slug = slug[:39]
	}
	return strings.TrimRight(slug, "-")
}
func validOnboardingDomain(domain string) bool {
	return len(domain) <= 39 && sitePattern.MatchString(domain) && !strings.HasSuffix(domain, "-")
}
