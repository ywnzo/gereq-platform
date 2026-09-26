package install

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"

	"gereq.dev/gereq-cli/internal/doctor"
	"gereq.dev/gereq-cli/internal/env"
)

func Demo(root string, port int, serve bool) error {
	checks := doctor.Run()
	if doctor.Print(checks) != 0 {
		return fmt.Errorf("doctor failed")
	}
	fmt.Println("→ composer install…")
	composerArgs := []string{"install", "--no-interaction", "--prefer-dist"}
	if exec.Command("php", "-m").Run() == nil {
		out, _ := exec.Command("php", "-m").Output()
		if !strings.Contains(strings.ToLower(string(out)), "redis") {
			fmt.Println("! ext-redis missing — ignore-platform-req")
			composerArgs = append(composerArgs, "--ignore-platform-req=ext-redis")
		}
	}
	if err := run(root, "composer", composerArgs...); err != nil {
		return err
	}

	appKey := envRandomKey()
	pubKey := "grq_pub_" + envRandomHex(24)
	appURL := fmt.Sprintf("http://127.0.0.1:%d", port)

	if _, err := os.Stat(filepath.Join(root, ".env")); err == nil {
		_ = backupEnv(root)
		fmt.Println("! existing .env backed up")
	}

	content := fmt.Sprintf(`# GEREQ RealEstates — demo package

APP_NAME="RealEstates Demo"
APP_ENV=local
APP_DEBUG=true
APP_URL=%s
APP_KEY=%s

TRUSTED_PROXIES=*

DB_CONNECTION=sqlite
DB_DATABASE=storage/database.sqlite
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE_NAME=gereq
DB_USERNAME=gereq
DB_PASSWORD=demo

CACHE_DRIVER=file
CACHE_TTL=3600
CACHE_TWIG=true
CACHE_DOCTRINE_RESULT=true

SEARCH_DRIVER=self_repair

QUEUE_DRIVER=sync
FILE_DRIVER=local

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

MAIL_DSN=null://null

ADMIN_NAME="Demo Admin"
ADMIN_EMAIL=demo@gereq.cz
ADMIN_PASSWORD=demo123

SESSION_DRIVER=file
SESSION_LIFETIME=120
SESSION_SAME_SITE=lax

WEBHOOKS_ALLOW_PRIVATE_URLS=true

FRONTEND_ORIGIN=http://127.0.0.1:5173,http://localhost:5173

API_PUBLIC_KEY=%s
`, appURL, appKey, pubKey)

	if err := os.WriteFile(filepath.Join(root, ".env"), []byte(content), 0o644); err != nil {
		return err
	}
	_ = os.MkdirAll(filepath.Join(root, "FE2"), 0o775)
	_ = os.WriteFile(filepath.Join(root, "FE2", ".env"), []byte(fmt.Sprintf("VITE_API_URL=http://127.0.0.1:%d\nVITE_API_PUBLIC_KEY=%s\n", port, pubKey)), 0o644)
	if err := env.EnsureStorage(root); err != nil {
		return err
	}
	db := filepath.Join(root, "storage", "database.sqlite")
	_ = os.WriteFile(db, []byte{}, 0o644)
	fmt.Println("✓ .env (SQLite, demo account)")

	js := pickJS()
	fmt.Printf("→ Frontend (%s)…\n", js)
	if js == "bun" {
		if err := run(root, "bun", "install"); err != nil {
			return err
		}
		if err := run(root, "bun", "run", "build"); err != nil {
			return err
		}
	} else {
		if err := run(root, "npm", "install"); err != nil {
			return err
		}
		if err := run(root, "npm", "run", "build"); err != nil {
			return err
		}
	}
	fmt.Println("✓ Assets in public/build")

	fmt.Println("→ migrate + demo seed…")
	if err := run(root, "php", "gereq", "migrate", "--fresh", "--seed", "--no-interaction"); err != nil {
		return err
	}
	fmt.Printf("\n✓ Done\n\n  Admin:   %s/admin\n  Email:   demo@gereq.cz\n  Password: demo123\n\n", appURL)
	if serve {
		fmt.Printf("→ php gereq serve --port=%d\n", port)
		cmd := exec.Command("php", "gereq", "serve", "--port="+strconv.Itoa(port))
		cmd.Dir = root
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr
		cmd.Stdin = os.Stdin
		return cmd.Run()
	}
	return nil
}

func Interactive(root string) error {
	checks := doctor.Run()
	if doctor.Print(checks) != 0 {
		return fmt.Errorf("doctor failed")
	}
	r := bufio.NewReader(os.Stdin)

	appName := ask(r, "App / client name", "GEREQ Platform")
	appURL := ask(r, "Public URL (APP_URL)", "http://127.0.0.1:8080")
	adminName := ask(r, "Admin name", "Administrátor")
	adminEmail := ask(r, "Admin email", "admin@example.com")
	adminPass := ask(r, "Admin password", "change-me")

	appEnv := choose(r, "Environment", [][2]string{{"local", "local (debug on)"}, {"production", "production (debug off)"}}, 0)
	appDebug := "true"
	if appEnv == "production" {
		appDebug = "false"
	}

	dbConn := choose(r, "Database", [][2]string{{"sqlite", "SQLite"}, {"mysql", "MySQL / MariaDB"}, {"pgsql", "PostgreSQL"}}, 0)
	dbDatabase := "storage/database.sqlite"
	dbHost, dbPort, dbName, dbUser, dbPass := "127.0.0.1", "3306", "gereq", "gereq", ""
	if dbConn == "sqlite" {
		dbDatabase = ask(r, "SQLite path", dbDatabase)
	} else {
		if dbConn == "pgsql" {
			dbPort = "5432"
		}
		dbHost = ask(r, "DB host", dbHost)
		dbPort = ask(r, "DB port", dbPort)
		dbName = ask(r, "DB name", dbName)
		dbUser = ask(r, "DB user", dbUser)
		dbPass = ask(r, "DB password", "")
	}

	queue, session, cache, redisHost := "sync", "file", "file", "127.0.0.1"
	useDocker := false
	webhooksPrivate := "false"
	hasDocker := false
	for _, c := range checks {
		if c.Name == "docker compose" && c.OK {
			hasDocker = true
		}
	}
	if hasDocker && yesno(r, "Run via Docker (nginx + PHP-FPM + Redis)?", true) {
		useDocker = true
		queue, session, redisHost = "redis", "redis", "redis"
		webhooksPrivate = "true"
	} else {
		queue = choose(r, "Queue driver", [][2]string{{"sync", "sync"}, {"redis", "redis"}, {"file", "file"}}, 0)
		if queue == "redis" {
			redisHost = ask(r, "Redis host", redisHost)
			session = "redis"
		}
	}
	if yesno(r, "Allow webhooks to private URLs?", false) {
		webhooksPrivate = "true"
	}
	withDemo := yesno(r, "Load demo business data?", false)
	withSample := yesno(r, "Create sample customers?", false)

	fmt.Printf("\n  App: %s\n  URL: %s\n  Env: %s (debug=%s)\n  DB: %s\n  Admin: %s <%s>\n  Queue: %s\n  Docker: %v\n\n",
		appName, appURL, appEnv, appDebug, dbConn, adminName, adminEmail, queue, useDocker)
	if !yesno(r, "Continue?", true) {
		fmt.Println("Cancelled.")
		return nil
	}

	appKey := envRandomKey()
	fmt.Println("→ composer install…")
	if err := run(root, "composer", "install", "--no-interaction", "--prefer-dist"); err != nil {
		return err
	}
	if _, err := os.Stat(filepath.Join(root, ".env")); err == nil {
		_ = backupEnv(root)
	}
	envBody := fmt.Sprintf(`# GEREQ Platform — generated by grq install

APP_NAME="%s"
APP_ENV=%s
APP_DEBUG=%s
APP_URL=%s
APP_KEY=%s

DB_CONNECTION=%s
DB_DATABASE=%s
DB_HOST=%s
DB_PORT=%s
DB_DATABASE_NAME=%s
DB_USERNAME=%s
DB_PASSWORD=%s

CACHE_DRIVER=%s
QUEUE_DRIVER=%s
FILE_DRIVER=local

REDIS_HOST=%s
REDIS_PORT=6379
REDIS_PASSWORD=

MAIL_DSN=null://null

ADMIN_NAME="%s"
ADMIN_EMAIL=%s
ADMIN_PASSWORD=%s

SESSION_DRIVER=%s
SESSION_LIFETIME=120
SESSION_SAME_SITE=lax

WEBHOOKS_ALLOW_PRIVATE_URLS=%s
`, escapeDQ(appName), appEnv, appDebug, appURL, appKey,
		dbConn, dbDatabase, dbHost, dbPort, dbName, dbUser, dbPass,
		cache, queue, redisHost,
		escapeDQ(adminName), adminEmail, adminPass, session, webhooksPrivate)

	if err := os.WriteFile(filepath.Join(root, ".env"), []byte(envBody), 0o644); err != nil {
		return err
	}
	_ = env.EnsureStorage(root)
	if dbConn == "sqlite" {
		p := dbDatabase
		if !filepath.IsAbs(p) {
			p = filepath.Join(root, p)
		}
		_ = os.MkdirAll(filepath.Dir(p), 0o775)
		_ = os.WriteFile(p, []byte{}, 0o644)
	}

	js := pickJS()
	fmt.Printf("→ Frontend (%s)…\n", js)
	if js == "bun" {
		_ = run(root, "bun", "install")
		_ = run(root, "bun", "run", "build")
	} else {
		_ = run(root, "npm", "install")
		_ = run(root, "npm", "run", "build")
	}

	mig := []string{"gereq", "migrate", "--fresh", "--seed", "--no-interaction"}
	if !withDemo {
		mig = append(mig, "--no-demo")
	}
	if !withSample {
		mig = append(mig, "--no-sample")
	}
	fmt.Println("→ migrate + seed…")
	if err := run(root, "php", mig...); err != nil {
		return err
	}

	if useDocker {
		port := "8080"
		if u := strings.TrimPrefix(strings.TrimPrefix(appURL, "https://"), "http://"); strings.Contains(u, ":") {
			parts := strings.Split(u, ":")
			if len(parts) > 1 {
				port = strings.Split(parts[1], "/")[0]
			}
		}
		os.Setenv("GEREQ_PORT", port)
		os.Setenv("APP_URL", appURL)
		fmt.Println("→ docker compose up…")
		_ = run(root, "docker", "compose", "up", "-d", "--build")
	}

	fmt.Printf("\n✓ Done\n\n  Admin: %s/admin\n  Email: %s\n\n", strings.TrimRight(appURL, "/"), adminEmail)
	return nil
}

func pickJS() string {
	if _, err := exec.LookPath("bun"); err == nil {
		return "bun"
	}
	return "npm"
}

func run(root, name string, args ...string) error {
	cmd := exec.Command(name, args...)
	cmd.Dir = root
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	cmd.Stdin = os.Stdin
	return cmd.Run()
}

func ask(r *bufio.Reader, prompt, def string) string {
	if def != "" {
		fmt.Printf("%s [%s]: ", prompt, def)
	} else {
		fmt.Printf("%s: ", prompt)
	}
	line, _ := r.ReadString('\n')
	line = strings.TrimSpace(line)
	if line == "" {
		return def
	}
	return line
}

func yesno(r *bufio.Reader, prompt string, defYes bool) bool {
	hint := "y/N"
	if defYes {
		hint = "Y/n"
	}
	fmt.Printf("%s [%s]: ", prompt, hint)
	line, _ := r.ReadString('\n')
	line = strings.TrimSpace(strings.ToLower(line))
	if line == "" {
		return defYes
	}
	return line == "y" || line == "yes"
}

func choose(r *bufio.Reader, title string, opts [][2]string, defIdx int) string {
	fmt.Println(title)
	for i, o := range opts {
		fmt.Printf("  %d) %s\n", i+1, o[1])
	}
	fmt.Printf("Choice [%d]: ", defIdx+1)
	line, _ := r.ReadString('\n')
	line = strings.TrimSpace(line)
	if line == "" {
		return opts[defIdx][0]
	}
	n, err := strconv.Atoi(line)
	if err != nil || n < 1 || n > len(opts) {
		return opts[defIdx][0]
	}
	return opts[n-1][0]
}

func backupEnv(root string) error {
	src := filepath.Join(root, ".env")
	dst := filepath.Join(root, fmt.Sprintf(".env.bak.%d", os.Getpid()))
	data, err := os.ReadFile(src)
	if err != nil {
		return err
	}
	return os.WriteFile(dst, data, 0o644)
}

func escapeDQ(s string) string {
	return strings.ReplaceAll(s, `"`, `\"`)
}

func envRandomKey() string {
	return envRandomHex(32)
}

func envRandomHex(n int) string {
	// reuse via php if available for consistency
	out, err := exec.Command("php", "-r", fmt.Sprintf("echo bin2hex(random_bytes(%d));", n)).Output()
	if err == nil && len(out) > 0 {
		return strings.TrimSpace(string(out))
	}
	b := make([]byte, n)
	f, _ := os.Open("/dev/urandom")
	if f != nil {
		_, _ = f.Read(b)
		f.Close()
	}
	const hexdigits = "0123456789abcdef"
	out2 := make([]byte, n*2)
	for i := 0; i < n; i++ {
		out2[i*2] = hexdigits[b[i]>>4]
		out2[i*2+1] = hexdigits[b[i]&0xf]
	}
	return string(out2)
}
