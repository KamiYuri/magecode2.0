// Package config assembles this service's runtime settings from the
// environment compose provides.
package config

import (
	"fmt"
	"net/url"
)

// Postgres are the pieces compose hands CES (docker-compose.yml, the
// code-executor block). shared/go/db wants a single DSN, so they are joined
// here rather than asking the deployment for a second spelling of the same
// credentials.
type Postgres struct {
	Host     string
	Port     int
	Database string
	User     string
	Password string
}

// DSN renders a libpq URL. Everything user-supplied is escaped, because a
// password is free-form and a stray `@` or `/` would otherwise re-point the
// connection at another host or database.
func (p Postgres) DSN() string {
	dsn := url.URL{
		Scheme: "postgres",
		User:   url.UserPassword(p.User, p.Password),
		Host:   fmt.Sprintf("%s:%d", p.Host, p.Port),
		Path:   "/" + p.Database,
	}

	// sslmode is not set here: the pool talks to PgBouncer inside the compose
	// network (D-79), and shared/go/db appends the query parameters it needs.
	return dsn.String()
}
