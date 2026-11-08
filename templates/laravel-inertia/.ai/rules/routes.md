---
paths:
  - 'routes/**'
---

# Routes

## Sign-in belongs to SESAME
SESAME registers `GET /login` and `POST /logout` and owns the rest of sign-in. Never add register, password reset, email verification or two-factor routes, and never install Fortify, Breeze or a starter kit's auth. Protect a route with the `auth.sesame` middleware.
