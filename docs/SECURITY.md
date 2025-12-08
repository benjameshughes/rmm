# RMM Security Architecture

## Attack Surface

### API Endpoints (Public)
Only 3 endpoints are exposed:
- `POST /api/enroll` - New device enrollment
- `POST /api/check` - Device approval status check
- `POST /api/metrics` - Heartbeat and metrics data

All other routes require authentication.

### Agent Authentication
- Every request requires valid `X-Agent-Key` header
- Keys are unique per-device, generated on approval
- Invalid keys are logged and rejected
- Keys stored locally on device with restricted file permissions (chmod 600)

---

## Security Controls

### Rate Limiting
Laravel rate limiting on API routes. Prevents brute force attacks.

```php
// Example: 60 requests per minute per IP
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(60)->by($request->ip());
});
```

### Request Logging
Log every API request with:
- Timestamp
- IP address
- Device ID (if authenticated)
- Action/endpoint
- Response status

### Alerts
Send notifications when:
- New device enrolls (requires approval anyway)
- Device hasn't checked in for X hours (offline alert)
- Multiple failed authentication attempts (potential attack)
- Script execution (when implemented)

---

## Script Execution (Future)

When adding remote script execution:

1. **Scripts stored in database** - Not user-input, pre-defined only
2. **Approval required** - Scripts must be approved before running
3. **Audit logging** - Full log of what ran, where, when, by whom
4. **2FA for dangerous ops** - Require 2FA to push scripts
5. **Output capture** - Log script output for review

---

## Network Isolation Options

### Basic (Current)
- HTTPS everywhere (TLS 1.2+)
- Devices connect outbound only
- No inbound connections to agents
- Forge firewall rules

### Enhanced (Optional)
- Cloudflare in front of Laravel (hides origin IP)
- Admin panel on separate subdomain
- IP whitelist for admin access
- VPN-only access for management

---

## Comparison to Commercial RMMs

| Concern | Commercial RMM | Your RMM |
|---------|---------------|----------|
| Code visibility | Closed source, unknown | You wrote it, you know it |
| Attack target | High value, big target | Small, personal, not worth targeting |
| Breach history | Kaseya, SolarWinds, ConnectWise | N/A |
| Update control | Forced updates | You control releases |
| Data location | Their cloud | Your server |

---

## Quick Security Checklist

- [ ] Rate limiting on API routes
- [ ] Logging middleware for all API requests
- [ ] Email alert on new device enrollment
- [ ] Email alert on device offline > X hours
- [ ] Failed auth attempt logging
- [ ] Forge firewall configured
- [ ] SSL certificate valid
- [ ] Admin panel behind authentication
- [ ] Regular Laravel/dependency updates

---

## Incident Response

If you suspect compromise:

1. **Revoke all device API keys** - Forces re-enrollment
2. **Check Laravel logs** - Look for suspicious requests
3. **Rotate secrets** - APP_KEY, database password
4. **Review Forge access logs** - Check for unauthorized access
5. **Audit devices** - Check what's actually installed

---

## Resources

- [Laravel Security Best Practices](https://laravel.com/docs/security)
- [OWASP API Security Top 10](https://owasp.org/www-project-api-security/)
- [Forge Security Features](https://forge.laravel.com/docs/servers/security.html)
