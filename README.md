# SIGAP-JPL — Level Crossing Monitoring & Safety Detection System

A Laravel-based web application for monitoring railway level crossings (JPL) under CCTV surveillance: counts passing vehicles (cars, motorcycles, bicycles, buses, trucks) and pedestrians, while detecting potential safety hazards — objects that stop or remain stationary too long within the crossing area. Detection and tracking are handled by a Python microservice. The Docker-based architecture supports both offline (on-site server, no internet after image build) and online (networked) deployment.

## Key Features

- **Multi-zone polygon drawing per video** — draw multiple zones directly on the video:
  - **Direction zones** — count objects crossing them, per class and per zone (e.g. "Left Track", "Right Track")
  - **Hazard zones** — flag objects that remain stationary in the zone beyond a configurable time threshold (default 5s) as a safety event, complete with automatic snapshot evidence
- **Per-object trail visualization** with consistent color per tracked ID, plus location name + timestamp overlay on the output video
- **Multi-location support** — each video is linked to a registered crossing location; dashboard shows summary and history per location
- **Offline dashboard & historical reports** — totals by video/object/safety event, filterable by location and date range, CSV export
- **Online dashboard** — live CCTV feed per location, active once an administrator configures the CCTV URL (HLS/MP4/embed)
- **Scheduled live detection** — administrators can schedule a live detection window (up to 6 hours) that runs the stream directly through the detection model without pre-recording
- **Role-based access** — Administrator (full access) and User (dashboard-only) roles

## Architecture

```
Browser (draw zones) --upload video + zones--> Laravel (app)
       ^                                            |
       +------------status/result-------------------+
                                                      | HTTP POST /process
                                                      v
                                          Python detector service
                                          (FastAPI + YOLOv8 + ByteTrack
                                           + zone/polygon logic)
                                                      | HTTP POST callback
                                                      v
                                          Laravel (app) - saves counts
                                          + safety events to DB
```

- `laravel-app/` — web app: manage crossing locations, upload video + draw zone polygons, dashboard (charts + tables + annotated video + safety event list)
- `detector-service/` — Python service running YOLOv8 detection, ByteTrack object tracking, per-zone counting, dwell-time hazard detection, and result callback to Laravel

## Running Locally (Docker & Docker Compose required)

```bash
cd vehicle-counter
docker compose up --build
```

- `http://localhost:8000` — login, then app
- `http://localhost:8000/dashboard` — summary and reports across all locations
- `http://localhost:8000/locations` — manage crossing locations
- `http://localhost:8001/health` — detector service health check

## Admin Account & User Management

A single initial Administrator account is created/updated automatically on container start, based on `ADMIN_EMAIL` / `ADMIN_PASSWORD` environment variables — set your own strong credentials before deploying. There's no public registration page; additional accounts (admin or dashboard-only "user" role) are created from the admin panel's User Management screen.

## Deploying via Coolify (example)

The example below uses placeholder domains — substitute your own.

1. **Push this project to a Git repository** (private recommended). Coolify deploys from the `docker-compose.yml` plus the full source.
2. **Point DNS** — create an A record for your chosen app domain (e.g. `yourapp.example.com`) pointing to your VPS.
3. **Create the resource in Coolify** (e.g. at `coolify.yourdomain.com`):
   - New Resource → Docker Compose → connect your repository
   - Coolify will detect 3 services: `app`, `queue`, `detector`
   - Assign a domain **only** to the `app` service (port 8000). Keep `queue` and `detector` private — internal Docker network only.
   - Set required environment variables: `DETECTOR_CALLBACK_SECRET` (random string), `APP_KEY` (Laravel app key), `APP_URL`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`
   - Deploy — Coolify provisions SSL automatically once DNS resolves correctly

## Notes Before Going Public-Facing

- All pages are login-protected, but the app currently supports a single admin role (no granular staff permissions) and no upload audit log yet — suitable for internal team use as-is
- Uses Laravel's built-in dev server (`php artisan serve`) — fine for light/internal traffic; consider `php-fpm` + `nginx` for heavier concurrent upload load
- Ensure adequate disk space — uploaded videos and annotated output are stored persistently

## Project Structure

```
vehicle-counter/
├── docker-compose.yml
├── laravel-app/        # Laravel 11 (PHP 8.2) — web app
│   ├── app/Models/            User, JplLocation, Video, VideoZone, CountResult, SafetyEvent
│   ├── app/Http/Controllers/  AuthController, VideoController, VideoCallbackController, ...
│   ├── app/Console/Commands/  EnsureAdminUser.php
│   └── app/Services/          DetectorClient.php
└── detector-service/    # Python (FastAPI)
    ├── app/main.py       # /process endpoint: YOLOv8 + tracking + overlay
    └── app/tracker.py    # ZoneTracker: per-zone counting + dwell-time detection
```

## Customization

- **Model:** set `MODEL_NAME` in `docker-compose.yml` (e.g. `yolov8s.pt` for higher accuracy)
- **Hazard dwell threshold:** `DANGER_DWELL_SECONDS` (default 5s)
- **Detection confidence threshold:** `CONF_THRESHOLD` (default 0.35)
- **Trail length:** `TRAIL_LENGTH` in the detector service (default 30 points)
- **Callback secret:** change `DETECTOR_CALLBACK_SECRET` before production use

## Technical Notes

- Custom session-based auth (not a third-party package), managed via `php artisan sigap:ensure-admin`
- Application code is baked into the Docker image at build time — rebuild after code changes
- Persistent data (SQLite database + uploaded/annotated videos) lives in Docker volumes
- Zones are stored as normalized polygons; hazard detection tracks dwell duration per tracked object with a small tolerance for detection flicker
- Video source is currently file upload (batch), not live RTSP — real-time RTSP ingestion could be added later

---
*Built for monitoring level crossings on a rail infrastructure project. This repository is shared as a portfolio reference — deployment domains, credentials, and any location-identifying data have been redacted/genericized.*
