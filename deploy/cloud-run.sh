#!/usr/bin/env bash
# Deploy the read-only demo to Google Cloud Run, with Cloud Scheduler keeping its data current.
#
#   PROJECT_ID=my-project DATABASE_URL='postgresql://...' deploy/cloud-run.sh
#
# DATABASE_URL is only needed the first time (it is stored in Secret Manager). Re-running the
# script builds and deploys the current checkout. Needs an authenticated gcloud and a project
# with billing enabled (the usage stays inside the free tier, but Google requires a billing account).
set -euo pipefail

: "${PROJECT_ID:?Set PROJECT_ID}"
REGION="${REGION:-us-central1}"          # Cloud Run's free tier only counts usage in some US regions
SERVICE="${SERVICE:-game-analytics-dashboard}"
SCHEDULE="${SCHEDULE:-*/10 * * * *}"      # how often new simulated events are added
IMAGE="$REGION-docker.pkg.dev/$PROJECT_ID/apps/$SERVICE"

gcloud config set project "$PROJECT_ID" >/dev/null

echo "Enabling APIs..."
gcloud services enable run.googleapis.com cloudbuild.googleapis.com artifactregistry.googleapis.com \
    cloudscheduler.googleapis.com secretmanager.googleapis.com

gcloud artifacts repositories describe apps --location "$REGION" >/dev/null 2>&1 \
    || gcloud artifacts repositories create apps --repository-format docker --location "$REGION"

create_secret() { # name, value
    if ! gcloud secrets describe "$1" >/dev/null 2>&1; then
        printf '%s' "$2" | gcloud secrets create "$1" --data-file=-
    fi
}

create_secret app-key "base64:$(openssl rand -base64 32)"
create_secret demo-refresh-token "$(openssl rand -hex 32)"
if ! gcloud secrets describe database-url >/dev/null 2>&1; then
    : "${DATABASE_URL:?Set DATABASE_URL to the Postgres connection string for the first deploy}"
    create_secret database-url "$DATABASE_URL"
fi

PROJECT_NUMBER="$(gcloud projects describe "$PROJECT_ID" --format 'value(projectNumber)')"
RUNTIME_ACCOUNT="$PROJECT_NUMBER-compute@developer.gserviceaccount.com"
for secret in app-key demo-refresh-token database-url; do
    gcloud secrets add-iam-policy-binding "$secret" --member "serviceAccount:$RUNTIME_ACCOUNT" \
        --role roles/secretmanager.secretAccessor >/dev/null
done

echo "Building $IMAGE..."
gcloud builds submit --tag "$IMAGE"

echo "Deploying $SERVICE..."
gcloud run deploy "$SERVICE" --image "$IMAGE" --region "$REGION" --allow-unauthenticated \
    --min-instances 0 --max-instances 1 --cpu 1 --memory 512Mi --timeout 3600 --cpu-boost \
    --set-env-vars "APP_ENV=production,DB_CONNECTION=pgsql,SESSION_DRIVER=database,CACHE_STORE=database,DEMO_MODE=true,TRUSTED_PROXIES=*" \
    --set-secrets "APP_KEY=app-key:latest,DB_URL=database-url:latest,DEMO_REFRESH_TOKEN=demo-refresh-token:latest"

URL="$(gcloud run services describe "$SERVICE" --region "$REGION" --format 'value(status.url)')"
gcloud run services update "$SERVICE" --region "$REGION" --update-env-vars "APP_URL=$URL" >/dev/null

TOKEN="$(gcloud secrets versions access latest --secret demo-refresh-token)"
JOB_FLAGS=(--location "$REGION" --schedule "$SCHEDULE" --time-zone UTC --uri "$URL/api/internal/demo-refresh"
    --http-method POST --attempt-deadline 30m --max-retry-attempts 0)
if gcloud scheduler jobs describe demo-refresh --location "$REGION" >/dev/null 2>&1; then
    gcloud scheduler jobs update http demo-refresh "${JOB_FLAGS[@]}" --update-headers "Authorization=Bearer $TOKEN"
else
    gcloud scheduler jobs create http demo-refresh "${JOB_FLAGS[@]}" --headers "Authorization=Bearer $TOKEN"
fi

echo "Building the demo data (the first run simulates the full history and takes a few minutes)..."
curl -fsS -X POST -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" --max-time 3600 "$URL/api/internal/demo-refresh"
echo
echo "Live at $URL"
