// Package version holds the agent's build version, overridable at link time
// via -ldflags "-X .../version.Version=1.2.3".
package version

// Version is the running agent version. The release script sets this at build
// time; the default marks an unversioned local build.
var Version = "0.0.0-dev"
