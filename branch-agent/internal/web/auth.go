package web

import (
	"crypto/rand"
	"encoding/hex"
	"net/http"
	"sync"
	"time"

	"golang.org/x/crypto/bcrypt"
)

const sessionCookie = "sg_session"

// sessionStore is an in-memory session table. Branch agents serve a handful of
// operators, so an in-process map is plenty; sessions don't survive a restart
// (operator logs in again), which is acceptable.
type sessionStore struct {
	mu       sync.Mutex
	sessions map[string]time.Time // id → expiry
	ttl      time.Duration
}

func newSessionStore(ttl time.Duration) *sessionStore {
	return &sessionStore{sessions: map[string]time.Time{}, ttl: ttl}
}

func (s *sessionStore) create() string {
	id := randHex(32)
	s.mu.Lock()
	s.sessions[id] = time.Now().Add(s.ttl)
	s.mu.Unlock()
	return id
}

func (s *sessionStore) valid(id string) bool {
	if id == "" {
		return false
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	exp, ok := s.sessions[id]
	if !ok {
		return false
	}
	if time.Now().After(exp) {
		delete(s.sessions, id)
		return false
	}
	return true
}

func (s *sessionStore) destroy(id string) {
	s.mu.Lock()
	delete(s.sessions, id)
	s.mu.Unlock()
}

// hashPassword returns a bcrypt hash suitable for storing in config.
func hashPassword(pw string) (string, error) {
	b, err := bcrypt.GenerateFromPassword([]byte(pw), bcrypt.DefaultCost)
	return string(b), err
}

func checkPassword(hash, pw string) bool {
	return bcrypt.CompareHashAndPassword([]byte(hash), []byte(pw)) == nil
}

func randHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

// setSessionCookie writes the session cookie. Not marked Secure because the
// agent is reached over plain HTTP on the branch LAN / tunnel.
func setSessionCookie(w http.ResponseWriter, id string, ttl time.Duration) {
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookie,
		Value:    id,
		Path:     "/",
		HttpOnly: true,
		SameSite: http.SameSiteLaxMode,
		Expires:  time.Now().Add(ttl),
	})
}

func clearSessionCookie(w http.ResponseWriter) {
	http.SetCookie(w, &http.Cookie{
		Name:     sessionCookie,
		Value:    "",
		Path:     "/",
		HttpOnly: true,
		MaxAge:   -1,
	})
}
