# The backup container: PostgreSQL's own client tools plus what off-machine copies and alerts need. The stock postgres image has
# neither curl nor rclone (so the BACKUP_UPLOAD_COMMAND / ALERT_COMMAND hooks could not reach anything), and this is the same
# image family as the database, so pg_dump always matches the server's major version.
FROM postgres:17
RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates rclone \
    && rm -rf /var/lib/apt/lists/*
