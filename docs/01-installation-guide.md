# Installation Guide

End-to-end installation of the Prestigious ICT Investment Limited platform
on a standard LAMP stack (Apache + PHP 8.2+ + MySQL 8.0.16+).

## Prerequisites

| Component | Minimum | Notes |
|---|---|---|
| PHP | 8.2 | `match`, `never` return, typed constants, `str_starts_with` |
| Extensions | PDO, pdo_mysql, mbstring, openssl, fileinfo, json, gd or imagick (optional) | `php -m` to verify |
| MySQL | 8.0.16 | `CHECK` constraints are enforced only from 8.0.16 |
| Apache | 2.4 | `mod_rewrite`, `mod_headers`, `mod_expires` recommended |
| Disk | 500 MB free minimum | More if CSV imports will be large |

Verify PHP:
