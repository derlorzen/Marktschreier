<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Sicherungen liegen in storage/app/backups. Mit Postgres erstellt sie der Dienst "backup"
 * (docker/backup.sh), lokal mit SQLite kopiert Marktschreier die Datenbankdatei selbst.
 */
class BackupService
{
    public function dir(): string
    {
        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    /** @return list<array{name: string, size: int, time: int}> neueste zuerst */
    public function list(): array
    {
        return collect(File::glob($this->dir().'/marktschreier-*.{sql,sqlite}.gz', GLOB_BRACE))
            ->map(fn ($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)])
            ->sortByDesc('time')->values()->all();
    }

    public function lastStatus(): ?string
    {
        $f = $this->dir().'/.letzte';

        return is_file($f) ? trim((string) file_get_contents($f)) : null;
    }

    /** Sofort sichern. Bei Postgres wird der Sicherungsdienst angestoßen. */
    public function now(): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite') {
            $source = DB::connection()->getDatabaseName();
            if (! is_file($source)) {
                return 'Es gibt keine Datenbankdatei zum Sichern.';
            }
            $target = $this->dir().'/marktschreier-'.now()->format('Ymd-His').'.sqlite.gz';
            file_put_contents($target, gzencode((string) file_get_contents($source), 6));
            file_put_contents($this->dir().'/.letzte', 'ok '.now()->toIso8601String().' '.$target);
            $this->prune();

            return 'Sicherung erstellt.';
        }
        touch($this->dir().'/.jetzt');

        return 'Sicherung angestoßen, sie erscheint in etwa einer Minute.';
    }

    public function prune(int $days = 14): void
    {
        foreach ($this->list() as $b) {
            if ($b['time'] < now()->subDays($days)->timestamp) {
                File::delete($this->dir().'/'.$b['name']);
            }
        }
    }

    public function path(string $name): ?string
    {
        if (! preg_match('/^marktschreier-\d{8}-\d{6}\.(sql|sqlite)\.gz$/', $name)) {
            return null;
        }
        $path = $this->dir().'/'.$name;

        return is_file($path) ? $path : null;
    }
}
