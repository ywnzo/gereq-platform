package main

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"sync"
	"syscall"
	"time"
)

// The socket is a privileged local interface. Authorization of human users must
// be enforced by the central backend before forwarding requests.
type backupAPI struct {
	engine     Engine
	dir        string
	mu         sync.Mutex
	active     bool
	jobContext context.Context
	workers    sync.WaitGroup
	execute    func(context.Context, backupAPIRequest, BackupConfig) (any, error)
}
type backupAPIRequest struct {
	Operation string `json:"operation"`
	Site      string `json:"site_id,omitempty"`
	Snapshot  string `json:"snapshot_id,omitempty"`
	KeepLast  int    `json:"keep_last,omitempty"`
}
type backupAPIJob struct {
	ID             string           `json:"id"`
	IdempotencyKey string           `json:"idempotency_key"`
	Request        backupAPIRequest `json:"request"`
	Status         string           `json:"status"`
	CreatedAt      time.Time        `json:"created_at"`
	FinishedAt     *time.Time       `json:"finished_at,omitempty"`
	Result         any              `json:"result,omitempty"`
	Error          string           `json:"error,omitempty"`
}

var apiKeyPattern = regexp.MustCompile(`^[A-Za-z0-9._-]{8,128}$`)
var apiJobPattern = regexp.MustCompile(`^job-[a-f0-9]{24}$`)

func newBackupAPI(e Engine) (*backupAPI, error) {
	if err := os.MkdirAll(e.Root, 0700); err != nil {
		return nil, err
	}
	dir := filepath.Join(e.Root, "backup-api")
	if err := privateBackupDir(dir); err != nil {
		return nil, err
	}
	jobs := filepath.Join(dir, "jobs")
	if err := privateBackupDir(jobs); err != nil {
		return nil, err
	}
	if _, err := e.cleanupBackupStages(); err != nil {
		return nil, err
	}
	if _, err := e.cleanupBackupVerification(); err != nil {
		return nil, err
	}
	if _, err := e.cleanupUpdateStages(); err != nil {
		return nil, err
	}
	a := &backupAPI{engine: e, dir: dir, jobContext: context.Background()}
	a.execute = a.executeJob
	entries, err := os.ReadDir(jobs)
	if err != nil {
		return nil, err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var j backupAPIJob
		if err = decode(filepath.Join(jobs, entry.Name()), &j); err != nil {
			return nil, errors.New("invalid stored backup job")
		}
		if j.Status == "running" {
			now := time.Now().UTC()
			j.Status = "interrupted"
			j.Error = "service restarted before completion; inspect repository before retrying"
			j.FinishedAt = &now
			if err = a.saveJob(&j); err != nil {
				return nil, err
			}
		}
	}
	return a, nil
}
func (a *backupAPI) saveJob(j *backupAPIJob) error {
	return atomicJSON(filepath.Join(a.dir, "jobs", j.ID+".json"), j)
}
func (a *backupAPI) resolveConfig() (BackupConfig, error) {
	c, err := loadBackupConfig(a.configPath())
	if err != nil {
		return c, err
	}
	if c.CredentialID != "" {
		c.secret, err = a.loadCredential(c.CredentialID)
	}
	return c, err
}
func (a *backupAPI) configPath() string { return filepath.Join(a.dir, "config.json") }
func (a *backupAPI) executeJob(ctx context.Context, r backupAPIRequest, c BackupConfig) (any, error) {
	switch r.Operation {
	case "init":
		_, err := runRestic(ctx, c, "init")
		return map[string]any{"initialized": true}, err
	case "create":
		return a.engine.BackupCreate(ctx, r.Site, c)
	case "scheduled":
		return a.runScheduled(ctx, r, c)
	case "recover":
		return a.engine.BackupRecover(ctx, r.Site)
	case "verify", "restore":
		return a.engine.BackupVerify(ctx, r.Site, r.Snapshot, c, r.Operation == "restore")
	}
	return nil, errors.New("unsupported operation")
}
func apiJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-store")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}
func apiError(w http.ResponseWriter, status int, message string) {
	apiJSON(w, status, map[string]string{"error": message})
}
func parseAPIJSON(w http.ResponseWriter, r *http.Request, v any) bool {
	return parseAPIJSONLimit(w, r, v, 16384)
}
func parseAPIJSONLimit(w http.ResponseWriter, r *http.Request, v any, limit int64) bool {
	if !strings.HasPrefix(r.Header.Get("Content-Type"), "application/json") {
		apiError(w, 415, "content type must be application/json")
		return false
	}
	d := json.NewDecoder(http.MaxBytesReader(w, r.Body, limit))
	d.DisallowUnknownFields()
	if d.Decode(v) != nil || d.Decode(new(any)) != io.EOF {
		apiError(w, 400, "invalid JSON body")
		return false
	}
	return true
}
func (a *backupAPI) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.RawQuery != "" {
		apiError(w, 400, "query parameters are not supported")
		return
	}
	switch {
	case r.URL.Path == "/v1/backup/credentials" && r.Method == http.MethodPost:
		var secret BackupSecrets
		if !parseAPIJSON(w, r, &secret) {
			return
		}
		a.mu.Lock()
		defer a.mu.Unlock()
		if a.active {
			apiError(w, 409, "backup job is running")
			return
		}
		id, err := a.createCredential(secret)
		if err != nil {
			apiError(w, 400, err.Error())
			return
		}
		apiJSON(w, 201, map[string]string{"credential_id": id})
	case r.URL.Path == "/v1/backup/config" && r.Method == http.MethodGet:
		a.mu.Lock()
		defer a.mu.Unlock()
		c, err := a.resolveConfig()
		if os.IsNotExist(err) {
			apiJSON(w, 200, map[string]any{"configured": false})
			return
		}
		if err != nil {
			apiError(w, 500, "backup configuration unavailable")
			return
		}
		apiJSON(w, 200, map[string]any{"configured": true, "repository": c.Repository, "password_configured": true, "credential_id": c.CredentialID})
	case r.URL.Path == "/v1/backup/config" && r.Method == http.MethodPut:
		var c BackupConfig
		if !parseAPIJSON(w, r, &c) {
			return
		}
		a.mu.Lock()
		defer a.mu.Unlock()
		if a.active {
			apiError(w, 409, "backup job is running")
			return
		}
		data, err := json.Marshal(c)
		if err != nil {
			apiError(w, 400, "invalid configuration")
			return
		}
		f, err := os.CreateTemp(a.dir, ".config-")
		if err != nil {
			apiError(w, 500, "could not validate configuration")
			return
		}
		tmp := f.Name()
		defer os.Remove(tmp)
		if err = f.Chmod(0600); err == nil {
			_, err = f.Write(data)
		}
		if closeErr := f.Close(); err == nil {
			err = closeErr
		}
		if err != nil {
			apiError(w, 500, "could not validate configuration")
			return
		}
		validated, validationErr := loadBackupConfig(tmp)
		if validationErr == nil && validated.CredentialID != "" {
			_, validationErr = a.loadCredential(validated.CredentialID)
		}
		if err = validationErr; err != nil {
			apiError(w, 400, err.Error())
			return
		}
		if err = atomicJSON(a.configPath(), c); err != nil {
			apiError(w, 500, "could not save configuration")
			return
		}
		apiJSON(w, 200, map[string]any{"configured": true, "repository": c.Repository, "password_configured": true, "credential_id": c.CredentialID})
	case strings.HasPrefix(r.URL.Path, "/v1/backup/schedules/") && (r.Method == http.MethodGet || r.Method == http.MethodPut):
		site := strings.TrimPrefix(r.URL.Path, "/v1/backup/schedules/")
		if !sitePattern.MatchString(site) {
			apiError(w, 400, "invalid site")
			return
		}
		if r.Method == http.MethodGet {
			a.mu.Lock()
			defer a.mu.Unlock()
			dir, err := a.scheduleDir()
			if err != nil {
				apiError(w, 500, "schedule storage unavailable")
				return
			}
			var policy backupPolicy
			if err = decode(filepath.Join(dir, site+".json"), &policy); os.IsNotExist(err) {
				apiJSON(w, 200, map[string]any{"configured": false})
				return
			} else if err != nil {
				apiError(w, 500, "invalid schedule")
				return
			}
			apiJSON(w, 200, policy)
			return
		}
		var input struct {
			Enabled         bool `json:"enabled"`
			IntervalSeconds int  `json:"interval_seconds"`
			KeepLast        int  `json:"keep_last"`
		}
		if !parseAPIJSON(w, r, &input) {
			return
		}
		if input.IntervalSeconds < 3600 || input.IntervalSeconds > 2678400 || input.KeepLast < 2 || input.KeepLast > 1000 {
			apiError(w, 400, "invalid schedule or retention")
			return
		}
		a.mu.Lock()
		defer a.mu.Unlock()
		dir, err := a.scheduleDir()
		if err != nil {
			apiError(w, 500, "schedule storage unavailable")
			return
		}
		policy := backupPolicy{Enabled: input.Enabled, IntervalSeconds: input.IntervalSeconds, KeepLast: input.KeepLast, NextRun: time.Now().UTC().Add(time.Duration(input.IntervalSeconds) * time.Second)}
		if err = atomicJSON(filepath.Join(dir, site+".json"), policy); err != nil {
			apiError(w, 500, "could not save schedule")
			return
		}
		apiJSON(w, 200, policy)
	case strings.HasPrefix(r.URL.Path, "/v1/backup/sites/") && strings.HasSuffix(r.URL.Path, "/snapshots") && r.Method == http.MethodGet:
		site := strings.TrimSuffix(strings.TrimPrefix(r.URL.Path, "/v1/backup/sites/"), "/snapshots")
		if !sitePattern.MatchString(site) {
			apiError(w, 400, "invalid site")
			return
		}
		a.mu.Lock()
		defer a.mu.Unlock()
		if a.active {
			apiError(w, 409, "backup job is running")
			return
		}
		c, err := a.resolveConfig()
		if err != nil {
			apiError(w, 409, "backup configuration unavailable")
			return
		}
		out, err := runRestic(r.Context(), c, "snapshots", "--json", "--host", "gereq-saas", "--tag", "gereq-site-"+site)
		if err != nil {
			apiError(w, 502, "could not list snapshots")
			return
		}
		var snapshots []struct {
			ID   string    `json:"id"`
			Time time.Time `json:"time"`
		}
		if json.Unmarshal(out, &snapshots) != nil {
			apiError(w, 502, "invalid snapshot listing")
			return
		}
		if snapshots == nil {
			snapshots = []struct {
				ID   string    `json:"id"`
				Time time.Time `json:"time"`
			}{}
		}
		apiJSON(w, 200, map[string]any{"site_id": site, "snapshots": snapshots})
	case r.URL.Path == "/v1/backup/jobs" && r.Method == http.MethodGet:
		a.mu.Lock()
		defer a.mu.Unlock()
		entries, err := os.ReadDir(filepath.Join(a.dir, "jobs"))
		if err != nil {
			apiError(w, 500, "job storage unavailable")
			return
		}
		jobs := make([]backupAPIJob, 0, len(entries))
		for _, entry := range entries {
			if !strings.HasSuffix(entry.Name(), ".json") {
				continue
			}
			var job backupAPIJob
			if decode(filepath.Join(a.dir, "jobs", entry.Name()), &job) != nil {
				apiError(w, 500, "job storage invalid")
				return
			}
			jobs = append(jobs, job)
		}
		sort.Slice(jobs, func(i, j int) bool { return jobs[i].CreatedAt.After(jobs[j].CreatedAt) })
		if len(jobs) > 100 {
			jobs = jobs[:100]
		}
		apiJSON(w, 200, map[string]any{"jobs": jobs})
	case r.URL.Path == "/v1/backup/jobs" && r.Method == http.MethodPost:
		var req backupAPIRequest
		if !parseAPIJSON(w, r, &req) {
			return
		}
		key := r.Header.Get("Idempotency-Key")
		if !apiKeyPattern.MatchString(key) {
			apiError(w, 400, "valid Idempotency-Key required")
			return
		}
		if req.Operation != "init" && req.Operation != "create" && req.Operation != "verify" && req.Operation != "restore" && req.Operation != "recover" && req.Operation != "scheduled" {
			apiError(w, 400, "invalid operation")
			return
		}
		if (req.Operation == "scheduled" && (req.KeepLast < 2 || req.KeepLast > 1000)) || (req.Operation != "scheduled" && req.KeepLast != 0) {
			apiError(w, 400, "invalid retention policy")
			return
		}
		if req.Operation == "init" {
			if req.Site != "" || req.Snapshot != "" {
				apiError(w, 400, "init does not take a site or snapshot")
				return
			}
		} else if !sitePattern.MatchString(req.Site) {
			apiError(w, 400, "invalid site")
			return
		}
		if (req.Operation == "verify" || req.Operation == "restore") != backupSnapshotPattern.MatchString(req.Snapshot) {
			apiError(w, 400, "invalid snapshot")
			return
		}
		a.mu.Lock()
		defer a.mu.Unlock()
		entries, err := os.ReadDir(filepath.Join(a.dir, "jobs"))
		if err != nil {
			apiError(w, 500, "job storage unavailable")
			return
		}
		for _, entry := range entries {
			if !strings.HasSuffix(entry.Name(), ".json") {
				continue
			}
			var old backupAPIJob
			if decode(filepath.Join(a.dir, "jobs", entry.Name()), &old) != nil {
				apiError(w, 500, "job storage invalid")
				return
			}
			if old.IdempotencyKey == key {
				if old.Request != req {
					apiError(w, 409, "idempotency key already used for another request")
					return
				}
				apiJSON(w, 200, old)
				return
			}
		}
		if a.active {
			apiError(w, 409, "backup job is running")
			return
		}
		var c BackupConfig
		if req.Operation != "recover" {
			c, err = a.resolveConfig()
			if err != nil {
				apiError(w, 409, "backup configuration unavailable")
				return
			}
		}
		random, err := randomSecret()
		if err != nil {
			apiError(w, 500, "could not create job")
			return
		}
		j := backupAPIJob{ID: "job-" + random[:24], IdempotencyKey: key, Request: req, Status: "running", CreatedAt: time.Now().UTC()}
		if err = a.saveJob(&j); err != nil {
			apiError(w, 500, "could not save job")
			return
		}
		a.active = true
		a.workers.Add(1)
		go func() {
			defer a.workers.Done()
			ctx, cancel := context.WithTimeout(a.jobContext, 6*time.Hour)
			defer cancel()
			result, err := a.execute(ctx, req, c)
			a.mu.Lock()
			defer a.mu.Unlock()
			now := time.Now().UTC()
			j.FinishedAt = &now
			if err != nil {
				j.Status = "failed"
				j.Error = err.Error()
				j.Result = result
			} else {
				j.Status = "complete"
				j.Result = result
			}
			if err = a.saveJob(&j); err == nil {
				a.active = false
			}
		}()
		apiJSON(w, 202, j)
	case strings.HasPrefix(r.URL.Path, "/v1/backup/jobs/") && r.Method == http.MethodGet:
		id := strings.TrimPrefix(r.URL.Path, "/v1/backup/jobs/")
		if !apiJobPattern.MatchString(id) {
			apiError(w, 404, "job not found")
			return
		}
		a.mu.Lock()
		defer a.mu.Unlock()
		var j backupAPIJob
		if err := decode(filepath.Join(a.dir, "jobs", id+".json"), &j); err != nil {
			apiError(w, 404, "job not found")
			return
		}
		apiJSON(w, 200, j)
	default:
		apiError(w, 404, "not found")
	}
}
func serveBackupAPI(ctx context.Context, e Engine, socket string) error {
	return serveLocalAPI(ctx, e, socket, "")
}
func serveLocalAPI(ctx context.Context, e Engine, socket, catalog string, centralMode ...bool) error {
	if !filepath.IsAbs(socket) {
		return errors.New("socket must be an absolute path")
	}
	if err := os.MkdirAll(e.Root, 0700); err != nil {
		return err
	}
	dir := filepath.Join(e.Root, "backup-api")
	if err := privateBackupDir(dir); err != nil {
		return err
	}
	lock, err := os.OpenFile(filepath.Join(dir, "server.lock"), os.O_CREATE|os.O_RDWR, 0600)
	if err != nil {
		return err
	}
	defer lock.Close()
	if err = syscall.Flock(int(lock.Fd()), syscall.LOCK_EX|syscall.LOCK_NB); err != nil {
		return errors.New("backup API already running")
	}
	defer syscall.Flock(int(lock.Fd()), syscall.LOCK_UN)
	a, err := newBackupAPI(e)
	if err != nil {
		return err
	}
	if err = privateBackupDir(filepath.Dir(socket)); err != nil {
		return errors.New("socket parent directory must be private")
	}
	jobContext, stopJobs := context.WithCancel(ctx)
	defer stopJobs()
	a.jobContext = jobContext
	listener, err := net.Listen("unix", socket)
	if err != nil {
		return err
	}
	defer listener.Close()
	if err = os.Chmod(socket, 0600); err != nil {
		return err
	}
	var handler http.Handler = a
	var centralBilling *centralAPI
	if catalog != "" || (len(centralMode) > 0 && centralMode[0]) {
		central, err := newCentralAPI(a, catalog)
		if err != nil {
			return err
		}
		handler = central
		centralBilling = central
	}
	billingDone := make(chan struct{})
	go func() {
		defer close(billingDone)
		if centralBilling != nil {
			centralBilling.runSubscriptionScheduler(jobContext)
		}
	}()
	schedulerDone := make(chan struct{})
	go func() { defer close(schedulerDone); a.startSchedules(jobContext) }()
	server := &http.Server{Handler: handler, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 15 * time.Second, WriteTimeout: 15 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 16384}
	go func() {
		<-ctx.Done()
		stop, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = server.Shutdown(stop)
	}()
	err = server.Serve(listener)
	stopJobs()
	<-schedulerDone
	<-billingDone
	a.workers.Wait()
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}
	return err
}
