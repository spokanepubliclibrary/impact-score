# Production Deployment Guide

## Pre-Deployment Checklist

1. **Build images locally** (or in CI/CD):
   ```bash
   make -f Makefile.prod build
   ```

2. **Push to registry** (Docker Hub, ECR, private registry):
   ```bash
   export REGISTRY=docker.io
   export IMAGE_NAMESPACE=yourusername
   export IMAGE_TAG=1.0.0
   make -f Makefile.prod push
   ```

3. **Configure production environment**:
   ```bash
   cp .env.production.example .env.production
   # Edit .env.production with real secrets and credentials
   ```

4. **Verify database migration**:
   - The migration `000_ensure_admin_user.sql` will run automatically
   - Admin user will be created with: username=`admin`, password=`changeme`
   - **The app refuses to serve requests (500) while this default password is
     still active** — see README.md → Production secret preflight for the
     `password_hash()` + `UPDATE admins` steps to rotate it before the app
     will come up

## Deployment

### Option A: Manual Deployment (single server)

```bash
# On your production server:
docker compose --env-file .env.production pull
docker compose --env-file .env.production up -d
```

### Option B: CI/CD Pipeline (GitHub Actions, GitLab CI, etc.)

Add to your CI/CD workflow:
```yaml
- name: Build and push images
  run: |
    export REGISTRY=${{ secrets.REGISTRY }}
    export IMAGE_NAMESPACE=${{ secrets.IMAGE_NAMESPACE }}
    export IMAGE_TAG=${{ github.ref_name }}-${{ github.sha }}
    make -f Makefile.prod push

- name: Deploy
  run: |
    ssh user@prod-server "cd /app && make -f Makefile.prod deploy"
```

## Post-Deployment

1. **Access the application**:
   - Web: http://your-domain:8080
   - The app returns 500 until the seeded `admin` / `changeme` password is
     rotated (see step 4 above) — this is enforced, not just a reminder

2. **View logs**:
   ```bash
   docker compose logs -f web
   docker compose logs -f db
   ```

3. **Backup database**:
   ```bash
   docker compose exec db mysqldump -u impact_user -p impact_score > backup.sql
   ```

## Security Best Practices

- [ ] Change default admin password immediately
- [ ] Use strong DB credentials in `.env.production`
- [ ] Enable HTTPS (reverse proxy / Let's Encrypt)
- [ ] Set `SESSION_SECURE_ONLY=true` when behind HTTPS
- [ ] Use secrets management (HashiCorp Vault, AWS Secrets Manager, etc.)
- [ ] Restrict database port (no public exposure)
- [ ] Enable Docker Content Trust for image verification
- [ ] Scan images for vulnerabilities: `docker scan impact-score:latest`

## Scaling & Orchestration

For multi-server production:
- **Kubernetes**: Use Helm charts to deploy
- **Docker Swarm**: Use stack deploy
- **AWS ECS/Fargate**: Use task definitions
- Ensure database is on persistent storage (managed RDS, persistent volumes)
