<?php

namespace App\Support\Docker;

use App\DataTransferObjects\DockerEvent;
use App\Exceptions\DockerBinaryNotFoundException;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;

/**
 * The long lived `docker events` stream this app listens to.
 *
 * Filtering happens on the Docker side wherever possible: the label filter
 * narrows the stream to ddev's own containers, and the event filters drop the
 * healthcheck `exec_*` chatter that would otherwise dominate it.
 */
class DockerEventStream
{
    public function __construct(private readonly DockerBinary $binary) {}

    /**
     * @return list<string>
     */
    public function command(): array
    {
        $eventFilters = [];

        foreach (DockerEvent::RELEVANT_ACTIONS as $action) {
            $eventFilters[] = '--filter';
            $eventFilters[] = 'event='.$action;
        }

        return [
            $this->binary->path(),
            'events',
            // Every container ddev creates carries this label, which is what
            // makes Docker usable as a ddev event source at all.
            '--filter',
            'label=com.ddev.platform=ddev',
            ...$eventFilters,
            '--format',
            '{{json .}}',
        ];
    }

    /**
     * @throws DockerBinaryNotFoundException
     */
    public function start(): InvokedProcess
    {
        return Process::forever()
            // docker resolves its context out of the home directory, and a
            // GUI-launched app may start in "/".
            ->path((string) (getenv('HOME') ?: sys_get_temp_dir()))
            ->start($this->command());
    }
}
