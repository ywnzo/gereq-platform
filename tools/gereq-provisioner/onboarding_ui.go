package main

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"embed"
	"encoding/hex"
	"encoding/json"
	"errors"
	"html/template"
	"io"
	"net"
	"net/http"
	"net/mail"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

//go:embed onboarding/index.html onboarding/app.css onboarding/app.js onboarding/domain.js
var onboardingAssets embed.FS

type onboardingInput struct {
	Domain     string   `json:"domain"`
	PackageID  string   `json:"package_id"`
	TemplateID string   `json:"template_id"`
	Name       string   `json:"name"`
	Offer      string   `json:"offer"`
	Audience   string   `json:"audience"`
	Language   string   `json:"language"`
	Goal       string   `json:"goal"`
	BookingURL string   `json:"booking_url"`
	Facts      []string `json:"facts"`
	Email      string   `json:"email"`
}
type onboardingRecord struct {
	Key     string          `json:"key"`
	Hash    string          `json:"hash"`
	Request customerRequest `json:"request"`
	JobID   string          `json:"job_id"`
}
type onboardingUI struct {
	root, host, token, release string
	client                     *http.Client
	mu                         sync.Mutex
}

func newOnboardingUI(root, socket, host, release string) (*onboardingUI, error) {
	if !filepath.IsAbs(socket) || !sitePattern.MatchString(release) {
		return nil, errors.New("absolute API socket and approved release ID required")
	}
	if err := privateBackupDir(root); err != nil {
		return nil, err
	}
	token, err := randomSecret()
	if err != nil {
		return nil, err
	}
	transport := &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "unix", socket)
	}}
	return &onboardingUI{root: root, host: host, token: token, release: release, client: &http.Client{Transport: transport, Timeout: 120 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return errors.New("upstream redirect refused") }}}, nil
}
func (u *onboardingUI) upstream(ctx context.Context, method, path string, body any, key string) (int, []byte, error) {
	var raw []byte
	var err error
	if body != nil {
		raw, err = json.Marshal(body)
		if err != nil {
			return 0, nil, err
		}
	}
	req, err := http.NewRequestWithContext(ctx, method, "http://local"+path, bytes.NewReader(raw))
	if err != nil {
		return 0, nil, err
	}
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if key != "" {
		req.Header.Set("Idempotency-Key", key)
	}
	response, err := u.client.Do(req)
	if err != nil {
		return 0, nil, errors.New("Centrální služba není dostupná. Zkuste to prosím znovu.")
	}
	defer response.Body.Close()
	raw, err = io.ReadAll(io.LimitReader(response.Body, (4<<20)+1))
	if err != nil || len(raw) > 4<<20 {
		return 0, nil, errors.New("Neplatná odpověď centrální služby.")
	}
	return response.StatusCode, raw, nil
}
func (u *onboardingUI) recordPath(key string) string {
	sum := sha256.Sum256([]byte(key))
	return filepath.Join(u.root, hex.EncodeToString(sum[:])+".json")
}
func (u *onboardingUI) readRecord(key string) (onboardingRecord, error) {
	var record onboardingRecord
	err := decode(u.recordPath(key), &record)
	if err == nil && record.Key != key {
		err = errors.New("request identity mismatch")
	}
	return record, err
}
func (u *onboardingUI) catalog(ctx context.Context) (map[string]any, error) {
	status, raw, err := u.upstream(ctx, "GET", "/v1/catalog/templates", nil, "")
	if err != nil || status != 200 {
		return nil, errors.New("Nabídku šablon se nepodařilo načíst.")
	}
	var templates struct {
		Templates []catalogTemplate `json:"templates"`
	}
	if json.Unmarshal(raw, &templates) != nil {
		return nil, errors.New("Neplatná nabídka šablon.")
	}
	usable := map[string]bool{}
	choices := []map[string]any{}
	for _, t := range templates.Templates {
		if t.Active && !t.Archived && (t.Driver == "native-json" || t.Driver == "ai-editorial") {
			usable[t.ID] = true
			choices = append(choices, map[string]any{"id": t.ID, "name": t.Name, "description": t.Description, "fixed_layout": t.Driver == "native-json"})
		}
	}
	status, raw, err = u.upstream(ctx, "GET", "/v1/catalog/packages", nil, "")
	if err != nil || status != 200 {
		return nil, errors.New("Nabídku balíčků se nepodařilo načíst.")
	}
	var packages struct {
		Packages []catalogPackage `json:"packages"`
	}
	if json.Unmarshal(raw, &packages) != nil {
		return nil, errors.New("Neplatná nabídka balíčků.")
	}
	status, raw, err = u.upstream(ctx, "GET", "/v1/releases/"+u.release, nil, "")
	var target registeredRelease
	if err != nil || status != 200 || json.Unmarshal(raw, &target) != nil {
		return nil, errors.New("Cílovou verzi platformy se nepodařilo načíst.")
	}
	packageChoices := []map[string]any{}
	for _, p := range packages.Packages {
		if !p.Active || p.Archived || p.RuntimePackage != "funnel-v1" || !packageFeatureEnabled(&p, "website_generation") || !packageFeatureEnabled(&p, "draft_import") || (p.GenerationLimit != nil && *p.GenerationLimit == 0) || validateSelectedModules(Request{EnabledModules: p.Modules}, target.Release) != nil {
			continue
		}
		ids := []string{}
		for _, id := range p.TemplateIDs {
			if usable[id] {
				ids = append(ids, id)
			}
		}
		if len(ids) > 0 {
			packageChoices = append(packageChoices, map[string]any{"id": p.ID, "name": p.Name, "description": p.Description, "template_ids": ids})
		}
	}
	return map[string]any{"packages": packageChoices, "templates": choices}, nil
}
func (u *onboardingUI) reservePort(ctx context.Context, domain string) (int, error) {
	reserved := map[int]bool{}
	entries, err := os.ReadDir(u.root)
	if err != nil {
		return 0, err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var r onboardingRecord
		if decode(filepath.Join(u.root, entry.Name()), &r) != nil {
			return 0, errors.New("invalid local reservation")
		}
		if r.Request.SiteID == domain {
			return 0, errOnboardingDomainTaken
		}
		reserved[r.Request.Port] = true
	}
	status, raw, err := u.upstream(ctx, "GET", "/v1/instances", nil, "")
	if err != nil || status != 200 {
		return 0, errors.New("Stav místních instancí není dostupný.")
	}
	var instances struct {
		Instances []struct {
			SiteID     string `json:"site_id"`
			PreviewURL string `json:"preview_url"`
			Status     string `json:"status"`
		} `json:"instances"`
	}
	if json.Unmarshal(raw, &instances) != nil {
		return 0, errors.New("Neplatný stav instancí.")
	}
	for _, instance := range instances.Instances {
		if instance.SiteID == domain {
			return 0, errOnboardingDomainTaken
		}
		if instance.Status == "inspection_required" {
			return 0, errors.New("Některá instance vyžaduje kontrolu správce.")
		}
		parsed, err := url.Parse(instance.PreviewURL)
		if err == nil {
			port, _ := strconv.Atoi(parsed.Port())
			reserved[port] = true
		}
	}
	for port := 18200; port < 18300; port++ {
		if reserved[port] {
			continue
		}
		listener, err := net.Listen("tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)))
		if err == nil {
			_ = listener.Close()
			return port, nil
		}
	}
	return 0, errors.New("Pro další místní web není volný port.")
}
func (u *onboardingUI) submit(ctx context.Context, record *onboardingRecord) (int, []byte, error) {
	status, raw, err := u.upstream(ctx, "POST", "/v1/customers", record.Request, "onboarding-"+record.Key)
	if err != nil {
		return 0, nil, err
	}
	if status == 400 && record.JobID == "" {
		if err = os.Remove(u.recordPath(record.Key)); err != nil && !os.IsNotExist(err) {
			return 0, nil, err
		}
	}
	if status == 200 || status == 202 {
		var job struct {
			ID string `json:"id"`
		}
		if json.Unmarshal(raw, &job) != nil || !apiJobPattern.MatchString(job.ID) {
			return 0, nil, errors.New("Neplatný průběh vytváření.")
		}
		record.JobID = job.ID
		if err = atomicJSON(u.recordPath(record.Key), record); err != nil {
			return 0, nil, errors.New("Průběh se nepodařilo uložit. Obnovte požadavek.")
		}
	}
	return status, raw, nil
}
func (u *onboardingUI) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	w.Header().Set("Referrer-Policy", "no-referrer")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Content-Security-Policy", "default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'")
	if r.Host != u.host {
		apiError(w, 403, "Tato stránka je dostupná jen z místní adresy.")
		return
	}
	if r.URL.RawQuery != "" {
		apiError(w, 400, "Parametry v adrese nejsou podporovány.")
		return
	}
	if r.Method == http.MethodGet && r.URL.Path == "/" {
		http.SetCookie(w, &http.Cookie{Name: "gereq-onboarding", Value: u.token, Path: "/", HttpOnly: true, SameSite: http.SameSiteStrictMode})
		w.Header().Set("Content-Type", "text/html; charset=utf-8")
		page, _ := template.ParseFS(onboardingAssets, "onboarding/index.html")
		_ = page.Execute(w, map[string]string{"Token": u.token})
		return
	}
	if r.Method == http.MethodGet && (r.URL.Path == "/app.js" || r.URL.Path == "/domain.js" || r.URL.Path == "/app.css") {
		if strings.HasSuffix(r.URL.Path, ".js") {
			w.Header().Set("Content-Type", "text/javascript; charset=utf-8")
		} else {
			w.Header().Set("Content-Type", "text/css; charset=utf-8")
		}
		raw, _ := onboardingAssets.ReadFile("onboarding" + r.URL.Path)
		_, _ = w.Write(raw)
		return
	}
	cookie, err := r.Cookie("gereq-onboarding")
	if err != nil || !hmac.Equal([]byte(cookie.Value), []byte(u.token)) {
		apiError(w, 401, "Obnovte stránku a pokračujte.")
		return
	}
	if r.Method != http.MethodGet && (r.Header.Get("Origin") != "http://"+u.host || !hmac.Equal([]byte(r.Header.Get("X-CSRF-Token")), []byte(u.token))) {
		apiError(w, 403, "Požadavek se nepodařilo ověřit. Obnovte stránku.")
		return
	}
	u.mu.Lock()
	defer u.mu.Unlock()
	if r.URL.Path == "/ui/catalog" && r.Method == http.MethodGet {
		catalog, err := u.catalog(r.Context())
		if err != nil {
			apiError(w, 503, err.Error())
			return
		}
		apiJSON(w, 200, catalog)
		return
	}
	key := r.Header.Get("Idempotency-Key")
	if !apiKeyPattern.MatchString(key) || len(key) > 80 {
		apiError(w, 400, "Chybí identifikátor vašeho požadavku.")
		return
	}
	if r.URL.Path == "/ui/create" && r.Method == http.MethodPost {
		var in onboardingInput
		if !parseAPIJSON(w, r, &in) {
			return
		}
		raw, _ := json.Marshal(in)
		sum := sha256.Sum256(raw)
		hash := hex.EncodeToString(sum[:])
		record, err := u.readRecord(key)
		if err == nil {
			if record.Hash != hash {
				apiError(w, 409, "Tento požadavek má jiné zadání. Začněte nový návrh.")
				return
			}
			u.respond(w, r, &record, "submit")
			return
		}
		if !os.IsNotExist(err) {
			apiError(w, 500, "Uložený požadavek není dostupný.")
			return
		}
		site := in.Domain
		if site == "" {
			site = domainFromName(in.Name)
		}
		if !validOnboardingDomain(site) {
			apiJSON(w, 400, map[string]any{"error": "Doména musí mít 3–39 malých písmen, číslic nebo pomlček a začínat písmenem.", "field": "domain"})
			return
		}
		brief := Brief{Schema: 1, Name: strings.TrimSpace(in.Name), Offer: strings.TrimSpace(in.Offer), Audience: strings.TrimSpace(in.Audience), Language: in.Language, Goal: in.Goal, BookingURL: strings.TrimSpace(in.BookingURL), Facts: in.Facts, Slug: "uvod"}
		address, err := mail.ParseAddress(in.Email)
		if err != nil || address.Address != in.Email || len(in.Email) > 180 {
			apiError(w, 400, "Zadejte platný e-mail.")
			return
		}
		if err = brief.validate(); err != nil {
			apiError(w, 400, "Zkontrolujte název, nabídku, publikum a odkaz na rezervace. Fakta mají nejvýše 12 řádků po 400 znacích.")
			return
		}
		choices, err := u.catalog(r.Context())
		if err != nil {
			apiError(w, 503, err.Error())
			return
		}
		allowed := false
		for _, choice := range choices["packages"].([]map[string]any) {
			if choice["id"] == in.PackageID && contains(choice["template_ids"].([]string), in.TemplateID) {
				allowed = true
			}
		}
		if !allowed {
			apiError(w, 400, "Vybraná šablona není dostupná pro tento balíček. Obnovte nabídku.")
			return
		}
		port, err := u.reservePort(r.Context(), site)
		if errors.Is(err, errOnboardingDomainTaken) {
			apiJSON(w, 409, map[string]any{"error": "Tato doména už je obsazená. Vyberte jinou.", "field": "domain"})
			return
		}
		if err != nil {
			apiError(w, 409, err.Error())
			return
		}
		record = onboardingRecord{Key: key, Hash: hash, Request: customerRequest{SiteID: site, CustomerRef: "local-" + site, Email: in.Email, PackageID: in.PackageID, TemplateID: in.TemplateID, ReleaseID: u.release, Port: port, Brief: brief}}
		if err = atomicJSON(u.recordPath(key), &record); err != nil {
			apiError(w, 500, "Požadavek se nepodařilo uložit.")
			return
		}
		u.respond(w, r, &record, "submit")
		return
	}
	record, err := u.readRecord(key)
	if err != nil {
		apiError(w, 404, "Uložený návrh nebyl nalezen.")
		return
	}
	if r.URL.Path == "/ui/recover" && r.Method == http.MethodPost {
		u.respond(w, r, &record, "submit")
		return
	}
	if r.URL.Path == "/ui/current" && r.Method == http.MethodGet {
		if record.JobID == "" {
			apiJSON(w, 200, map[string]any{"status": "submission_pending", "phase": "provisioning"})
			return
		}
		u.respond(w, r, &record, "status")
		return
	}
	if r.URL.Path == "/ui/retry" && r.Method == http.MethodPost && record.JobID != "" {
		u.respond(w, r, &record, "retry")
		return
	}
	if r.URL.Path == "/ui/setup-link" && r.Method == http.MethodPost && record.JobID != "" {
		u.respond(w, r, &record, "setup-link")
		return
	}
	apiError(w, 404, "Stránka nebyla nalezena.")
}
func (u *onboardingUI) respond(w http.ResponseWriter, r *http.Request, record *onboardingRecord, action string) {
	var status int
	var raw []byte
	var err error
	if action == "submit" {
		status, raw, err = u.submit(r.Context(), record)
	} else {
		path := "/v1/customers/" + record.JobID
		method := "GET"
		if action != "status" {
			method = "POST"
			path += "/" + action
		}
		status, raw, err = u.upstream(r.Context(), method, path, nil, "")
	}
	if err != nil {
		apiError(w, 503, err.Error())
		return
	}
	if status >= 400 {
		message := "Požadavek se nepodařilo dokončit. Zkuste to znovu."
		if status == 409 {
			message = "Probíhá jiná úloha nebo tento krok ještě není dostupný. Počkejte chvíli a zkuste to znovu."
		}
		if status == 400 {
			message = "Zadání nebo vybraná šablona nejsou dostupné. Zkontrolujte údaje a zkuste nový návrh."
		}
		apiError(w, status, message)
		return
	}
	if action == "setup-link" {
		var access struct {
			SetupURL string `json:"setup_url"`
		}
		if json.Unmarshal(raw, &access) != nil {
			apiError(w, 502, "Neplatný odkaz pro přístup.")
			return
		}
		parsed, err := url.Parse(access.SetupURL)
		if err != nil || parsed.Scheme != "http" || parsed.Hostname() != record.Request.SiteID+".localhost" || parsed.Port() != strconv.Itoa(record.Request.Port) || !strings.HasPrefix(parsed.Path, "/admin/reset-password/") {
			apiError(w, 502, "Neplatný odkaz pro přístup.")
			return
		}
	}
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_, _ = w.Write(raw)
}
func serveOnboardingUI(ctx context.Context, root, socket, address, release string) error {
	host, port, err := net.SplitHostPort(address)
	if err != nil || net.ParseIP(host) == nil || !net.ParseIP(host).IsLoopback() {
		return errors.New("onboarding UI must listen on a loopback IP and explicit port")
	}
	n, err := strconv.Atoi(port)
	if err != nil || n < 1024 || n > 65535 {
		return errors.New("invalid onboarding UI port")
	}
	u, err := newOnboardingUI(root, socket, address, release)
	if err != nil {
		return err
	}
	unlock, err := (Engine{Root: root}).lock()
	if err != nil {
		return err
	}
	defer unlock()
	defer u.client.CloseIdleConnections()
	status, raw, err := u.upstream(ctx, "GET", "/v1/releases", nil, "")
	if err != nil || status != 200 {
		return errors.New("central release catalog unavailable")
	}
	var catalog struct {
		Releases []Release `json:"releases"`
	}
	if json.Unmarshal(raw, &catalog) != nil {
		return errors.New("invalid release catalog")
	}
	found := false
	for _, r := range catalog.Releases {
		if r.ID == release {
			found = true
		}
	}
	if !found {
		return errors.New("configured release is not approved")
	}
	listener, err := net.Listen("tcp", address)
	if err != nil {
		return err
	}
	defer listener.Close()
	server := &http.Server{Handler: u, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 15 * time.Second, WriteTimeout: 150 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 16384}
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
