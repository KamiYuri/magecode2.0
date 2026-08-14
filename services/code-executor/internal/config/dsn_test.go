package config

import "testing"

func TestDSNRendersALibpqURL(t *testing.T) {
	got := Postgres{
		Host: "pgbouncer", Port: 6432, Database: "magecode",
		User: "magecode", Password: "secret",
	}.DSN()

	if want := "postgres://magecode:secret@pgbouncer:6432/magecode"; got != want {
		t.Errorf("DSN() = %q, want %q", got, want)
	}
}

// A password is free-form. Left unescaped, an `@` would make the rest of it
// read as the host and the pool would dial somewhere else entirely.
func TestDSNEscapesCredentials(t *testing.T) {
	got := Postgres{
		Host: "pgbouncer", Port: 6432, Database: "magecode",
		User: "mage code", Password: "p@ss/w:rd?x",
	}.DSN()

	if want := "postgres://mage%20code:p%40ss%2Fw%3Ard%3Fx@pgbouncer:6432/magecode"; got != want {
		t.Errorf("DSN() = %q, want %q", got, want)
	}
}
