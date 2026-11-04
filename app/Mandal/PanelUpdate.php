<?php

namespace App\Mandal;

use App\Enums\ReleaseChannel;

/**
 * A build of the panel newer than the running one on its channel: a
 * release tag, or the head of the development branch.
 */
final readonly class PanelUpdate
{
    public function __construct(
        public ReleaseChannel $channel,
        /** The ref `panel:update` is pointed at: `v1.2.3` or `main`. */
        public string $ref,
        /** What tells this build apart: the version, or the commit SHA. */
        public string $id,
    ) {}

    /**
     * "Mandal 1.2.3" or "main@abc1234".
     */
    public function label(): string
    {
        return $this->channel === ReleaseChannel::Stable
            ? "Mandal {$this->id}"
            : "{$this->ref}@".substr($this->id, 0, 7);
    }

    /**
     * How the build is named in a notice: a release by its version, a
     * development build by its commit.
     */
    public function title(): string
    {
        return $this->channel === ReleaseChannel::Stable
            ? "Mandal {$this->id} is available"
            : 'Development build '.substr($this->id, 0, 7).' is available';
    }

    /**
     * The options that point `mandal panel:update` at this build.
     */
    public function arguments(): string
    {
        return $this->channel === ReleaseChannel::Stable
            ? "--version={$this->ref}"
            : "--branch={$this->ref}";
    }

    public function command(): string
    {
        return 'sudo mandal panel:update '.$this->arguments();
    }
}
