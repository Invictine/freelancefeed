You are working on a Proxmox host.

Goal:
- Build and maintain a FreshRSS-based leads dashboard.
- FreshRSS is the central RSS backend.
- FeedFlow will be the UI.
- Future modules may include Reddit feeds, Discord bot feeds, X API feeds, YTJobs scrapers, and RSSBridge/RSSHub.

Rules:
- Before creating a new project/app/service for a request, check the Proxmox host's existing VMs and containers for something similar already running. Reuse or extend the existing service when practical.
- Prefer Docker Compose for app stacks.
- Keep configs in /opt/rss-leads-stack.
- Do not delete Proxmox VMs, containers, storage, backups, or network configs unless explicitly asked.
- Before changing Proxmox networking, firewall, storage, or cluster config, explain the change.
- Use Asia/Kolkata timezone.
