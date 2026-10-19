#!/bin/sh
# Tägliche Datenbanksicherung für eine Marktschreier-Instanz.
# Läuft im postgres-Image, schreibt nach /backups (gemeinsames Volume mit der App).
# Eine Datei /backups/.jetzt löst sofort eine Sicherung aus (Knopf in den Einstellungen).
set -eu
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
mkdir -p /backups
while true; do
  latest=$(ls -1t /backups/marktschreier-*.sql.gz 2>/dev/null | head -n1 || true)
  due=0
  if [ -f /backups/.jetzt ]; then due=1; fi
  if [ -z "$latest" ] || [ -n "$(find "$latest" -mmin +1440 2>/dev/null)" ]; then due=1; fi
  if [ "$due" = 1 ]; then
    file="/backups/marktschreier-$(date +%Y%m%d-%H%M%S).sql.gz"
    if pg_dump -h postgres -U "$POSTGRES_USER" -d "$POSTGRES_DB" --no-owner | gzip > "$file.part"; then
      mv "$file.part" "$file"
      echo "ok $(date -Iseconds) $file" > /backups/.letzte
    else
      rm -f "$file.part"
      echo "fehler $(date -Iseconds)" > /backups/.letzte
    fi
    rm -f /backups/.jetzt
    find /backups -name 'marktschreier-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
  fi
  sleep 60
done
