package main

import (
	"context"
	"crypto/hmac"
	"embed"
	"encoding/json"
	"errors"
	"html/template"
	"net"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

//go:embed management/index.html management/app.css management/app.js management/package-editor.js management/navigation.js management/customer-view.js management/customer-auth.js management/mail-inbox.js management/domains.js
var managementAssets embed.FS

type managementUI struct{ bridge *onboardingUI }

func (u *managementUI) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	b := u.bridge
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Referrer-Policy", "no-referrer")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Content-Security-Policy", "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
	if r.Host != b.host {
		apiError(w, 403, "Použijte místní adresu správy.")
		return
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "Parametry v adrese nejsou podporovány.")
		return
	}
	if r.Method == "GET" && r.URL.Path == "/" {
		http.SetCookie(w, &http.Cookie{Name: "gereq-management", Value: b.token, Path: "/", HttpOnly: true, SameSite: http.SameSiteStrictMode})
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		page, _ := template.ParseFS(managementAssets, "management/index.html")
		_ = page.Execute(w, map[string]string{"Token": b.token})
		return
	}
	if r.Method == "GET" && (r.URL.Path == "/app.js" || r.URL.Path == "/app.css" || r.URL.Path == "/package-editor.js" || r.URL.Path == "/navigation.js" || r.URL.Path == "/customer-view.js" || r.URL.Path == "/customer-auth.js" || r.URL.Path == "/mail-inbox.js" || r.URL.Path == "/domains.js") {
		if strings.HasSuffix(r.URL.Path, ".js") {
			w.Header().Set("Content-Type", "text/javascript; charset=utf-8")
		} else {
			w.Header().Set("Content-Type", "text/css; charset=utf-8")
		}
		raw, _ := managementAssets.ReadFile("management" + r.URL.Path)
		_, _ = w.Write(raw)
		return
	}
	if u.customerProxy(w, r) {
		return
	}
	cookie, err := r.Cookie("gereq-management")
	if err != nil || !hmac.Equal([]byte(cookie.Value), []byte(b.token)) {
		apiError(w, 401, "Obnovte stránku správy.")
		return
	}
	if r.Method != "GET" && (r.Header.Get("Origin") != "http://"+b.host || !hmac.Equal([]byte(r.Header.Get("X-CSRF-Token")), []byte(b.token))) {
		apiError(w, 403, "Požadavek se nepodařilo ověřit. Obnovte stránku.")
		return
	}
	path := ""

	var body any
	if r.URL.Path == "/ui/customer-account-policy" && (r.Method == "GET" || r.Method == "PATCH") {
		path = "/v1/customer-account-policy"
		if r.Method == "PATCH" {
			var in struct {
				Max int `json:"max_sites_per_account"`
			}
			if !parseAPIJSON(w, r, &in) {
				return
			}
			body = in
		}
	}
	if r.URL.Path == "/ui/customer-mail-policy" && (r.Method == "GET" || r.Method == "PATCH") {
		path = "/v1/customer-mail-policy"
		if r.Method == "PATCH" {
			var in accountMailPolicy
			if !parseAPIJSON(w, r, &in) {
				return
			}
			body = in
		}
	}
	if r.Method == "GET" && r.URL.Path == "/ui/mail-simulator/messages" {
		path = "/v1/mail-simulator/messages"
	}
	if r.Method == "GET" && strings.HasPrefix(r.URL.Path, "/ui/mail-simulator/messages/") {
		id := strings.TrimPrefix(r.URL.Path, "/ui/mail-simulator/messages/")
		if len(id) == 29 && strings.HasPrefix(id, "mail-") {
			path = "/v1/mail-simulator/messages/" + id
		}
	}
	if r.Method == "GET" && r.URL.Path == "/ui/releases" {
		path = "/v1/management/releases"
	}
	if r.Method == "GET" && r.URL.Path == "/ui/overview" {
		path = "/v1/management/overview"
	}
	parts := strings.Split(strings.TrimPrefix(r.URL.Path, "/ui/"), "/")
	if r.Method == "GET" && contains([]string{"/ui/catalog/packages", "/ui/catalog/templates", "/ui/catalog/package-capabilities"}, r.URL.Path) {
		path = strings.Replace(r.URL.Path, "/ui/", "/v1/", 1)
	}
	if r.Method == "PUT" && len(parts) == 3 && parts[0] == "catalog" && parts[1] == "packages" && sitePattern.MatchString(parts[2]) {
		var input catalogPackage
		if !parseAPIJSON(w, r, &input) {
			return
		}
		body = input
		path = "/v1/catalog/packages/" + parts[2]
	}
	if len(parts) == 3 && parts[0] == "packages" && sitePattern.MatchString(parts[1]) && contains([]string{"preview", "apply", "options", "recover"}, parts[2]) {
		if (r.Method == "GET" && parts[2] == "options") || (r.Method == "POST" && parts[2] != "options") {
			if r.Method == "POST" {
				var input struct {
					PackageID string `json:"package_id,omitempty"`
					PlanID    string `json:"plan_id,omitempty"`
				}
				if !parseAPIJSON(w, r, &input) {
					return
				}
				body = input
			}
			path = "/v1/package-changes/" + parts[1] + "/" + parts[2]
		}
	}
	if r.Method == "GET" && r.URL.Path == "/ui/subscription-policy" {
		path = "/v1/subscription-policy"
	}
	if r.Method == "PATCH" && r.URL.Path == "/ui/subscription-policy" {
		var input struct {
			Interval   *int  `json:"check_interval_seconds"`
			Simulation *bool `json:"simulation_enabled"`
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		body = input
		path = "/v1/subscription-policy"
	}
	if r.Method == "POST" && len(parts) == 3 && parts[0] == "subscriptions" && sitePattern.MatchString(parts[1]) && contains([]string{"trial", "simulate-renewal", "simulate-expiry", "reconcile"}, parts[2]) {
		var input struct {
			TrialDays          *int   `json:"trial_days,omitempty"`
			ExpiresAt          string `json:"expires_at,omitempty"`
			Term               string `json:"term,omitempty"`
			ExpireAfterSeconds *int   `json:"expire_after_seconds,omitempty"`
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		if !apiKeyPattern.MatchString(r.Header.Get("Idempotency-Key")) {
			apiError(w, 400, "Chybí identifikátor akce.")
			return
		}
		body = input
		path = "/v1/subscriptions/" + parts[1] + "/" + parts[2]
	}

	if r.Method == "GET" && r.URL.Path == "/ui/local-images" {
		path = "/v1/local-images"
	}
	if r.Method == "POST" && r.URL.Path == "/ui/releases" {
		var input struct {
			Image           string `json:"image"`
			ExpectedImageID string `json:"expected_image_id,omitempty"`
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		if !apiKeyPattern.MatchString(r.Header.Get("Idempotency-Key")) {
			apiError(w, 400, "Chybí identifikátor akce.")
			return
		}
		body = input
		path = "/v1/releases"
	}
	if r.Method == "PATCH" && len(parts) == 2 && parts[0] == "releases" && sitePattern.MatchString(parts[1]) {
		var input struct {
			Active *bool `json:"active"`
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		body = input
		path = "/v1/releases/" + parts[1]
	}

	if r.Method == "GET" && len(parts) == 2 && (parts[0] == "jobs" || parts[0] == "workflows") && apiJobPattern.MatchString(parts[1]) {
		path = "/v1/management/" + parts[0] + "/" + parts[1]
	}
	if r.Method == "GET" && len(parts) == 3 && parts[0] == "instances" && sitePattern.MatchString(parts[1]) && parts[2] == "updates" {
		path = "/v1/management/instances/" + parts[1] + "/updates"
	}
	if r.Method == "POST" && len(parts) == 3 && parts[0] == "instances" && sitePattern.MatchString(parts[1]) && contains([]string{"inspect", "start", "stop", "retry", "setup-link", "update-plan", "update", "update-recover", "update-rollback"}, parts[2]) {
		path = "/v1/management/instances/" + parts[1] + "/" + parts[2]
		if !apiKeyPattern.MatchString(r.Header.Get("Idempotency-Key")) {
			apiError(w, 400, "Chybí identifikátor akce.")
			return
		}
	}
	if r.Method == "POST" && len(parts) == 3 && strings.HasPrefix(parts[2], "update") {
		var input struct {
			ReleaseID       string `json:"release_id,omitempty"`
			ExpectedImageID string `json:"expected_image_id,omitempty"`
			CurrentRelease  string `json:"current_release,omitempty"`
			UpdateID        string `json:"update_id,omitempty"`
			AcceptDataLoss  bool   `json:"accept_data_loss,omitempty"`
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		body = input
	}
	if r.URL.Path == "/ui/domains" || strings.HasPrefix(r.URL.Path, "/ui/domains/") {
		domainParts := strings.Split(strings.TrimPrefix(r.URL.Path, "/ui/domains"), "/")
		valid := r.URL.Path == "/ui/domains" && (r.Method == "GET" || r.Method == "POST")
		if len(domainParts) == 2 && r.Method == "DELETE" {
			_, err := domainHostname(domainParts[1])
			valid = err == nil
		}
		if len(domainParts) == 3 && r.Method == "POST" && contains([]string{"simulate", "primary"}, domainParts[2]) {
			_, err := domainHostname(domainParts[1])
			valid = err == nil
		}
		if !valid {
			apiError(w, 404, "Doménová akce nebyla nalezena.")
			return
		}
		path = "/v1" + strings.TrimPrefix(r.URL.Path, "/ui")
		if r.Method != "GET" {
			var input domainInput
			if !parseAPIJSON(w, r, &input) {
				return
			}
			body = input
		}
	}
	if path == "" {
		apiError(w, 404, "Stránka nebyla nalezena.")
		return
	}
	status, raw, err := b.upstream(r.Context(), r.Method, path, body, r.Header.Get("Idempotency-Key"))
	if err != nil {
		apiError(w, 503, "Centrální služba není dostupná. Zkuste to znovu.")
		return
	}
	if status >= 400 {
		var source struct {
			Error string `json:"error"`
		}
		_ = json.Unmarshal(raw, &source)
		message := "Akci se nepodařilo dokončit. Obnovte stav a zkuste to znovu."
		if strings.HasPrefix(path, "/v1/domains") {
			message = source.Error
		}
		if strings.HasPrefix(path, "/v1/catalog/") {
			message = packageEditorError(source.Error)
		}
		if strings.Contains(source.Error, "package feature disabled") {
			message = "Tato funkce je v přiřazeném balíčku vypnutá. Změňte balíček v detailu instance."
		}
		if strings.Contains(source.Error, "generation limit") {
			message = "Limit generování cílového balíčku je nižší než dosavadní využití."
		}
		if strings.Contains(source.Error, "preview again") {
			message = "Náhled už není aktuální. Připravte nový náhled změny."
		}
		if strings.Contains(source.Error, "package already assigned") {
			message = "Tato verze balíčku je již přiřazená."
		}
		if strings.Contains(source.Error, "target package unavailable") {
			message = "Cílový balíček již není dostupný."
		}
		if strings.Contains(source.Error, "subscription expired") {
			message = "Zkušební doba nebo předplatné vypršelo. Nejdříve obnovte předplatné."
		}
		if strings.Contains(source.Error, "already enrolled") {
			message = "Instance už má předplatné. Zkušební dobu nelze založit znovu."
		}
		if strings.Contains(source.Error, "simulation is disabled") {
			message = "Simulace plateb je v nastavení vypnutá."
		}
		if strings.Contains(source.Error, "trial days") {
			message = "Délka zkušební doby musí být 0 až 365 dní."
		}
		if strings.Contains(source.Error, "expiry delay") {
			message = "Simulované vypršení musí být za 0 až 86400 sekund."
		}

		if strings.Contains(source.Error, "different content") {
			message = "Tato verze už existuje s jiným obsahem. Vytvořte obraz s novým ID release."
		}
		if strings.Contains(source.Error, "selected image changed") {
			message = "Obraz se od výběru změnil. Načtěte znovu lokální obrazy."
		}
		if strings.Contains(source.Error, "not installed locally") {
			message = "Obraz není dostupný lokálně. Načtěte znovu seznam obrazů."
		}
		if strings.Contains(source.Error, "provenance") || strings.Contains(source.Error, "module versions") || strings.Contains(source.Error, "module set") {
			message = "Obraz nemá platná metadata podporovaného GEREQ release."
		}
		if strings.Contains(source.Error, "invalid local image") {
			message = "Vyberte platný lokální obraz."
		}

		if strings.HasPrefix(source.Error, "update plan rejected:") {
			message = "Aktualizace není kompatibilní nebo chybí podmínky pro zálohu. " + strings.TrimPrefix(source.Error, "update plan rejected:")
		}
		if strings.Contains(source.Error, "instance changed") {
			message = "Instance se změnila. Připravte nový plán aktualizace."
		}
		if strings.Contains(source.Error, "another job") || strings.Contains(source.Error, "current operation") {
			message = "Právě probíhá jiná úloha. Počkejte na její dokončení."
		}
		if strings.Contains(source.Error, "already complete") {
			message = "Nastavení účtu už je dokončené. Použijte přihlášení nebo běžnou obnovu hesla."
		}
		if strings.Contains(source.Error, "owner email") {
			message = "Pro tuto instanci chybí dokončené vytvoření účtu s e-mailem vlastníka."
		}
		if strings.Contains(source.Error, "start and verify") {
			message = "Nejdříve spusťte instanci a ověřte její stav."
		}
		apiError(w, status, message)
		return
	}
	if strings.HasSuffix(path, "/setup-link") {
		inspectStatus, siteRaw, err := b.upstream(r.Context(), "GET", "/v1/management/instances/"+parts[1], nil, "")
		var site managedInstance
		var access struct {
			SetupURL string `json:"setup_url"`
		}
		if err != nil || inspectStatus != 200 || json.Unmarshal(siteRaw, &site) != nil || json.Unmarshal(raw, &access) != nil {
			apiError(w, 502, "Odkaz se nepodařilo ověřit.")
			return
		}
		target, parseErr := url.Parse(access.SetupURL)
		expected, expectedErr := url.Parse(site.AdminURL)
		if parseErr != nil || expectedErr != nil || target.Scheme != "http" || target.Host != expected.Host || target.User != nil || !strings.HasPrefix(target.Path, "/admin/reset-password/") {
			apiError(w, 502, "Odkaz nepatří k vybrané instanci.")
			return
		}
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_, _ = w.Write(raw)
}
func serveManagementUI(ctx context.Context, root, socket, address string) error {
	host, port, err := net.SplitHostPort(address)
	if err != nil || net.ParseIP(host) == nil || !net.ParseIP(host).IsLoopback() {
		return errors.New("management UI must listen on a loopback IP and explicit port")
	}
	n, err := strconv.Atoi(port)
	if err != nil || n < 1024 || n > 65535 {
		return errors.New("invalid management UI port")
	}
	bridge, err := newOnboardingUI(root, socket, address, "local-management")
	if err != nil {
		return err
	}
	defer bridge.client.CloseIdleConnections()
	unlock, err := (Engine{Root: root}).lock()
	if err != nil {
		return err
	}
	defer unlock()
	status, _, err := bridge.upstream(ctx, "GET", "/v1/management/overview", nil, "")
	if err != nil || status != 200 {
		return errors.New("central management API unavailable")
	}
	listener, err := net.Listen("tcp", address)
	if err != nil {
		return err
	}
	defer listener.Close()
	server := &http.Server{Handler: &managementUI{bridge: bridge}, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 15 * time.Second, WriteTimeout: 150 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 16384}
	stopped := make(chan struct{})
	defer close(stopped)
	go func() {
		select {
		case <-ctx.Done():
			shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			_ = server.Shutdown(shutdown)
		case <-stopped:
		}
	}()
	err = server.Serve(listener)
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}
