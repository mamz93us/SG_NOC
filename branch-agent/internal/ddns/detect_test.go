package ddns

import (
	"context"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestFetchIPValid(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("203.0.113.7\n"))
	}))
	defer srv.Close()

	ip, err := fetchIP(context.Background(), srv.URL)
	if err != nil {
		t.Fatalf("fetchIP: %v", err)
	}
	if ip != "203.0.113.7" {
		t.Errorf("ip = %q, want 203.0.113.7", ip)
	}
}

func TestFetchIPRejectsNonIP(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("<html>error</html>"))
	}))
	defer srv.Close()

	if _, err := fetchIP(context.Background(), srv.URL); err == nil {
		t.Errorf("expected error for non-IP response")
	}
}
