package main

import (
	"context"
	"encoding/json"
	"errors"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"strconv"
	"strings"
	"time"
)

type domainResolution struct {
	Site    string `json:"site_id"`
	Port    int    `json:"port"`
	Host    string `json:"upstream_host"`
	Primary string `json:"primary"`
}
type localDomainRouter struct {
	resolve func(context.Context, string) (domainResolution, int, error)
	port    string
}

func (p *localDomainRouter) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("X-Gereq-Local-Domain-Simulation", "true")
	rawHost := r.Host
	if strings.Contains(rawHost, ":") {
		h, port, e := net.SplitHostPort(rawHost)
		if e != nil || port != p.port {
			http.Error(w, "invalid local router host", 400)
			return
		}
		rawHost = h
	}
	host, e := domainHostname(rawHost)
	if e != nil {
		http.Error(w, "unknown domain", 404)
		return
	}
	route, status, e := p.resolve(r.Context(), host)
	if e != nil || status != 200 {
		if status != 404 {
			status = 503
		}
		http.Error(w, "domain or instance unavailable", status)
		return
	}
	if !sitePattern.MatchString(route.Site) || route.Port < 1024 || route.Port > 65535 || route.Host != route.Site+".localhost" {
		http.Error(w, "invalid route", 503)
		return
	}
	if route.Primary != "" && route.Primary != host {
		primary, err := domainHostname(route.Primary)
		if err != nil {
			http.Error(w, "invalid primary", 503)
			return
		}
		target := url.URL{Scheme: "http", Host: net.JoinHostPort(primary, p.port), Path: r.URL.Path, RawPath: r.URL.RawPath, RawQuery: r.URL.RawQuery}
		http.Redirect(w, r, target.String(), http.StatusTemporaryRedirect)
		return
	}
	// Resolve through fixed loopback only. Never use client input as an upstream URL.
	target := &url.URL{Scheme: "http", Host: net.JoinHostPort("127.0.0.1", strconv.Itoa(route.Port))}
	upstreamHost := net.JoinHostPort(route.Host, strconv.Itoa(route.Port))
	incoming := r.Host
	proxy := httputil.NewSingleHostReverseProxy(target)
	original := proxy.Director
	proxy.Director = func(q *http.Request) {
		original(q)
		q.Host = upstreamHost
		for _, key := range []string{"Forwarded", "X-Forwarded-Host", "X-Forwarded-Proto", "X-Forwarded-For", "X-Real-IP", "X-Original-URL", "X-Rewrite-URL"} {
			q.Header.Del(key)
		}
		q.Header["X-Forwarded-For"] = nil
		// Preserve same-origin checks for the simulated gateway without accepting foreign origins.
		if origin := q.Header.Get("Origin"); origin != "" {
			if origin == "http://"+incoming {
				q.Header.Set("Origin", "http://"+upstreamHost)
			}
		}
		if ref, err := url.Parse(q.Header.Get("Referer")); err == nil && ref.Host == incoming && ref.Scheme == "http" {
			ref.Host = upstreamHost
			q.Header.Set("Referer", ref.String())
		}
	}
	proxy.Transport = &http.Transport{Proxy: nil, DialContext: (&net.Dialer{Timeout: 5 * time.Second}).DialContext, ResponseHeaderTimeout: 30 * time.Second, DisableKeepAlives: true}
	proxy.ModifyResponse = func(response *http.Response) error {
		if loc := response.Header.Get("Location"); loc != "" {
			u, err := url.Parse(loc)
			if err == nil && u.Host == upstreamHost {
				u.Scheme = "http"
				u.Host = incoming
				response.Header.Set("Location", u.String())
			}
		}
		return nil
	}
	proxy.ErrorHandler = func(w http.ResponseWriter, _ *http.Request, _ error) {
		http.Error(w, "customer instance unavailable", 503)
	}
	proxy.ServeHTTP(w, r)
}
func serveDomainRouter(ctx context.Context, socket, listen string) error {
	host, port, e := net.SplitHostPort(listen)
	if e != nil || host != "127.0.0.1" || port != "18154" {
		return errors.New("local domain router must bind 127.0.0.1:18154")
	}
	if !strings.HasPrefix(socket, "/") {
		return errors.New("private absolute socket required")
	}
	transport := &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
		return (&net.Dialer{}).DialContext(ctx, "unix", socket)
	}}
	bridge := &onboardingUI{client: &http.Client{Transport: transport, Timeout: 10 * time.Second}}
	handler := &localDomainRouter{port: port}
	handler.resolve = func(ctx context.Context, host string) (domainResolution, int, error) {
		status, raw, e := bridge.upstream(ctx, "GET", "/v1/domain-route/"+host, nil, "")
		var route domainResolution
		if e == nil && status == 200 {
			e = json.Unmarshal(raw, &route)
		}
		return route, status, e
	}
	listener, e := net.Listen("tcp", listen)
	if e != nil {
		return e
	}
	server := &http.Server{Handler: handler, ReadHeaderTimeout: 5 * time.Second, ReadTimeout: 60 * time.Second, WriteTimeout: 60 * time.Second, IdleTimeout: 30 * time.Second, MaxHeaderBytes: 64 << 10}
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()
	e = server.Serve(listener)
	if errors.Is(e, http.ErrServerClosed) {
		return nil
	}
	return e
}
