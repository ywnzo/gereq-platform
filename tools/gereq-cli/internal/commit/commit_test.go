package commit

import "testing"

func TestValidateConventional(t *testing.T) {
	t.Parallel()
	ok := []string{
		"feat: add login",
		"fix(shop): handle nil cart",
		"chore: pin Customers to v1.6.4",
		"refactor(api)!: drop legacy route",
		"docs: update testing guide",
		"Merge branch 'dev-features/testing' into 'dev'",
		"fixup! feat: add login",
	}
	for _, msg := range ok {
		if err := validateConventional(msg); err != nil {
			t.Fatalf("expected OK for %q: %v", msg, err)
		}
	}
	bad := []string{
		"rename comment",
		"Feat: wrong case",
		"feat:",
		"feat: ",
		"update stuff",
	}
	for _, msg := range bad {
		if err := validateConventional(msg); err == nil {
			t.Fatalf("expected error for %q", msg)
		}
	}
}
