<?php

namespace App\Services\Ssh;

use App\Services\Deploy\DeploymentCancelled;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;
use Throwable;

/**
 * Runs provisioning scripts inside a freshly created container over SSH.
 */
class SshRunner
{
    public function __construct(private DeployerKey $key) {}

    /**
     * Seconds between two reads of a running script; cancel and the deadline are checked as often.
     */
    public const READ_INTERVAL = 2;

    /**
     * Grace period (seconds) on top of the remote "timeout" before the PHP side gives up.
     */
    public const LOCAL_GRACE = 30;

    /**
     * Retry until sshd inside the container accepts the deployer key.
     *
     * @param  list<string>  $hostKeys  expected SHA256 fingerprints of the server host key (as
     *                                  printed by ssh-keygen, with or without "SHA256:"); when given, any other key aborts
     *                                  the connection before the login. Empty = no check.
     */
    public function connect(string $host, int $timeout = 240, ?callable $onWait = null, ?callable $shouldCancel = null,
        array $hostKeys = []): SSH2
    {
        $expected = array_values(array_unique(array_filter(array_map([static::class, 'normalizeFingerprint'], $hostKeys))));
        $deadline = microtime(true) + $timeout;
        $interval = (float) config('proxapps.retry_interval', 3);
        $attempt = 0;
        $last = 'Zeitüberschreitung';

        do {
            $attempt++;
            if ($shouldCancel && $shouldCancel()) {
                throw new DeploymentCancelled;
            }
            $name = 'PermissionDenied';
            try {
                $ssh = $this->newClient($host);
                if ($expected) {
                    $this->verifyHostKey($ssh, $host, $expected);
                }
                if ($ssh->login('root', $this->key->privateKey())) {
                    $ssh->setKeepAlive(20);

                    return $ssh;
                }
                $last = 'Anmeldung mit dem Deployer-Schlüssel abgelehnt';
            } catch (DeploymentCancelled|HostKeyMismatch $e) {
                throw $e;
            } catch (Throwable $e) {
                $name = class_basename($e);
                $last = trim($e->getMessage()) !== '' ? trim($e->getMessage()) : $name;
            }
            if ($onWait && $attempt % 5 === 1) {
                $onWait("Warte auf SSH ({$host}): {$name}");
            }
            if ($interval > 0 && microtime(true) + $interval < $deadline) {
                usleep((int) ($interval * 1_000_000));
            }
        } while (microtime(true) < $deadline);

        throw new SshException("SSH auf {$host} nicht erreichbar: {$last}");
    }

    /**
     * @param  list<string>  $expected  normalized fingerprints
     */
    private function verifyHostKey(SSH2 $ssh, string $host, array $expected): void
    {
        $key = $ssh->getServerPublicHostKey();
        if (! is_string($key) || $key === '') {
            throw new SshException('Der SSH-Hostschlüssel konnte nicht gelesen werden.');
        }
        $fp = static::fingerprint($key);
        if ($fp === null || ! in_array($fp, $expected, true)) {
            $ssh->disconnect();
            throw new HostKeyMismatch("Der SSH-Hostschlüssel von {$host} (SHA256:".($fp ?? '?').') passt nicht zu '
                .'den Schlüsseln, die Proxmox beim Anlegen erzeugt hat – Verbindung aus Sicherheitsgründen abgebrochen '
                .'(antwortet ein anderes Gerät auf dieser IP?).');
        }
    }

    /**
     * OpenSSH-style SHA256 fingerprint (base64 without padding) of a host key given as
     * "<type> <base64 blob>" or as the bare base64 blob.
     */
    public static function fingerprint(string $hostKey): ?string
    {
        $parts = preg_split('~\s+~', trim($hostKey)) ?: [];
        $b64 = count($parts) >= 2 ? $parts[1] : ($parts[0] ?? '');
        $blob = base64_decode($b64, true);
        if ($blob === false || $blob === '') {
            return null;
        }

        return rtrim(base64_encode(hash('sha256', $blob, true)), '=');
    }

    public static function normalizeFingerprint(string $fp): string
    {
        $fp = trim($fp);
        if (stripos($fp, 'SHA256:') === 0) {
            $fp = substr($fp, 7);
        }

        return rtrim($fp, '=');
    }

    protected function newClient(string $host): SSH2
    {
        return new SFTP($host, 22, 8);
    }

    /**
     * Upload the script plus an env file to a private temp dir and run it with bash, streaming the
     * merged output line by line.
     *
     * The script runs as "setsid -w timeout --kill-after=15 <secs> bash <dir>/run.sh": the remote
     * "timeout" kills the whole process group when the time is up, and setsid gives the script its
     * own process group so that "pkill -f <dir>/run.sh" (which also hits "timeout", which then
     * signals the group) stops it on cancel. The output is read non-blocking, so $tick (cancel
     * check, may throw) and the local deadline are evaluated every READ_INTERVAL seconds even when
     * the script prints nothing. The env file is deleted by run.sh right after sourcing it; the
     * directory is always removed afterwards – a failed cleanup is reported via $onWarn.
     *
     * @param  array<string, scalar|null>  $env
     * @param  list<string>  $secrets
     */
    public function runScript(SSH2 $ssh, string $script, array $env, callable $onLine, array $secrets, int $timeout = 1800,
        ?callable $tick = null, ?callable $onWarn = null): int
    {
        $timeout = max(1, $timeout);
        $dir = '/root/.proxapps-'.bin2hex(random_bytes(8));
        $qdir = escapeshellarg($dir);

        $envFile = '';
        foreach ($env as $k => $v) {
            if (! preg_match('~^[A-Za-z_][A-Za-z0-9_]*$~D', (string) $k)) {
                continue;
            }
            $envFile .= $k.'='.static::shellQuote((string) $v)."\n";
        }
        $runner = "set -a\n. {$qdir}/env\nset +a\nrm -f {$qdir}/env\n"
            ."set -euo pipefail\nexport DEBIAN_FRONTEND=noninteractive\ncd /root\n"
            .$script."\n";

        $ssh->setTimeout(30);
        $ssh->exec("umask 077 && mkdir -m 700 {$qdir}");
        $started = false;
        $finished = false;
        try {
            $this->upload($ssh, $dir.'/env', $envFile);
            $this->upload($ssh, $dir.'/run.sh', $runner);

            $buffer = '';
            $emit = function (string $line) use ($onLine, $secrets) {
                $line = rtrim($line, "\r");
                if (trim($line) !== '') {
                    $onLine(static::mask(mb_scrub($line, 'UTF-8'), $secrets));
                }
            };

            $begin = microtime(true);
            $deadline = $begin + $timeout + static::LOCAL_GRACE;
            $ssh->setTimeout(30);
            if ($ssh->exec("setsid -w timeout --kill-after=15 {$timeout} bash {$qdir}/run.sh 2>&1", false) === false) {
                throw new SshException('Das Skript konnte nicht gestartet werden.');
            }
            $started = true;
            $ssh->setTimeout(static::READ_INTERVAL);
            while ($ssh->isInteractiveChannelOpen(SSH2::CHANNEL_EXEC)) {
                $chunk = $ssh->read('', SSH2::READ_NEXT, SSH2::CHANNEL_EXEC);
                if (is_string($chunk) && $chunk !== '') {
                    $buffer .= $chunk;
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $emit(substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);
                    }
                } elseif ($chunk === false) {
                    throw new SshException('Die SSH-Verbindung wurde während des Skripts unterbrochen.');
                }
                if ($tick) {
                    $tick();
                }
                if (microtime(true) > $deadline) {
                    throw new SshException("Zeitüberschreitung beim Ausführen des Skripts (nach {$timeout} s)");
                }
            }
            if (trim($buffer) !== '') {
                $emit(trim($buffer));
            }
            $finished = true;
            $status = $ssh->getExitStatus();
            $code = $status === false ? 255 : (int) $status;
            if ($code === 124 && microtime(true) - $begin >= $timeout - 1) {
                throw new SshException("Zeitüberschreitung beim Ausführen des Skripts (nach {$timeout} s)");
            }

            return $code;
        } finally {
            $this->cleanup($ssh, $dir, $started && ! $finished, $onWarn);
        }
    }

    /**
     * Stop a still running script (on abort) and remove the temp dir. Never throws.
     */
    private function cleanup(SSH2 $ssh, string $dir, bool $aborted, ?callable $onWarn): void
    {
        $qdir = escapeshellarg($dir);
        $problems = [];
        try {
            $ssh->setTimeout(30);
            if ($ssh->isInteractiveChannelOpen(SSH2::CHANNEL_EXEC)) {
                $ssh->reset(SSH2::CHANNEL_EXEC);
            }
            if ($aborted) {
                // "[/]" keeps the pattern from matching the shell that runs pkill itself.
                $ssh->exec('pkill -f '.escapeshellarg('[/]'.ltrim($dir, '/').'/run.sh').'; true');
            }
            $ssh->exec("rm -rf {$qdir}");
            if ($ssh->getExitStatus() !== 0 && $ssh->getExitStatus() !== false) {
                $problems[] = 'Exit-Code '.$ssh->getExitStatus();
            }
        } catch (Throwable $e) {
            $problems[] = trim($e->getMessage()) !== '' ? trim($e->getMessage()) : class_basename($e);
        }
        if ($problems) {
            $message = "Aufräumen im Container fehlgeschlagen ({$dir}): ".implode('; ', $problems)
                .($aborted ? ' – das Skript läuft eventuell noch.' : '');
            Log::warning($message);
            if ($onWarn) {
                try {
                    $onWarn($message);
                } catch (Throwable) {
                    // the warning must never replace the original error
                }
            }
        }
    }

    private function upload(SSH2 $ssh, string $path, string $content): void
    {
        if ($ssh instanceof SFTP) {
            if (! $ssh->put($path, $content)) {
                throw new SshException("Datei {$path} konnte nicht übertragen werden.");
            }
            $ssh->chmod(0600, $path);

            return;
        }
        // Plain SSH fallback: transfer base64 encoded inside the command.
        $ssh->exec('umask 077 && printf %s '.escapeshellarg(base64_encode($content)).' | base64 -d > '.escapeshellarg($path));
        if ((int) $ssh->getExitStatus() !== 0) {
            throw new SshException("Datei {$path} konnte nicht übertragen werden.");
        }
    }

    public static function shellQuote(string $value): string
    {
        if ($value !== '' && preg_match('~^[A-Za-z0-9_@%+=:,./-]+$~D', $value)) {
            return $value;
        }

        return "'".str_replace("'", "'\"'\"'", $value)."'";
    }

    /**
     * @param  array<int, string|null>  $secrets
     */
    public static function mask(string $text, array $secrets): string
    {
        foreach ($secrets as $s) {
            if ($s !== null && mb_strlen($s) >= 4) {
                $text = str_replace($s, '••••••', $text);
            }
        }

        return $text;
    }
}
