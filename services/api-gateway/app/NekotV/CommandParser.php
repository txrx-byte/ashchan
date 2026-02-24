<?php
declare(strict_types=1);

/*
 * Copyright 2026 txrx-byte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace App\NekotV;

/**
 * Parses NekotV media commands from post body text.
 *
 * Supported commands (one per line, starting with '.'):
 *   .play <URL>      — Add a video to the playlist
 *   .remove <URL>    — Remove a video from the playlist
 *   .skip            — Skip the current video
 *   .pause           — Pause playback
 *   .unpause         — Resume playback
 *   .seek <TIME>     — Seek to a time (supports MM:SS, HH:MM:SS, or seconds)
 *   .clear           — Clear the entire playlist
 *
 * Port of meguca's common/vars.go MediaComRegexp + parser/body.go.
 * Regex: (?m)^\.(?:(play|remove|seek)\s+(\S+)|(seek|pause|unpause|skip|clear))$
 *
 * @see meguca/common/vars.go (MediaComRegexp)
 * @see meguca/parser/body.go
 */
final class CommandParser
{
    /**
     * Regex matching media commands in post body text.
     *
     * Group 1: command with argument (play|remove|seek)
     * Group 2: the argument (URL or timestamp)
     * Group 3: command without argument (pause|unpause|skip|clear)
     */
    private const COMMAND_PATTERN = '/^\.(?:(play|remove|seek|rate)\s+(\S+)|(pause|unpause|skip|clear))$/m';

    /** Maximum number of commands per post. */
    private const MAX_COMMANDS_PER_POST = 10;

    /**
     * Parse all media commands from a post body.
     *
     * @return list<MediaCommand>
     */
    public static function parse(string $body): array
    {
        $commands = [];

        if (preg_match_all(self::COMMAND_PATTERN, $body, $matches, PREG_SET_ORDER) === false) {
            return [];
        }

        foreach ($matches as $match) {
            if (count($commands) >= self::MAX_COMMANDS_PER_POST) {
                break;
            }

            // Group 1+2: commands with arguments
            if (!empty($match[1])) {
                $cmd = strtolower($match[1]);
                $arg = $match[2];

                $command = match ($cmd) {
                    'play'   => new MediaCommand(MediaCommandType::ADD_VIDEO, $arg),
                    'remove' => new MediaCommand(MediaCommandType::REMOVE_VIDEO, $arg),
                    'seek'   => new MediaCommand(MediaCommandType::SET_TIME, $arg),
                    'rate'   => new MediaCommand(MediaCommandType::SET_RATE, $arg),
                    default  => null,
                };

                if ($command !== null) {
                    $commands[] = $command;
                }
                continue;
            }

            // Group 3: commands without arguments
            if (!empty($match[3])) {
                $cmd = strtolower($match[3]);

                $command = match ($cmd) {
                    'pause'   => new MediaCommand(MediaCommandType::PAUSE),
                    'unpause' => new MediaCommand(MediaCommandType::PLAY),
                    'skip'    => new MediaCommand(MediaCommandType::SKIP_VIDEO),
                    'clear'   => new MediaCommand(MediaCommandType::CLEAR_PLAYLIST),
                    default   => null,
                };

                if ($command !== null) {
                    $commands[] = $command;
                }
            }
        }

        return $commands;
    }

    /**
     * Parse a timestamp string into seconds.
     *
     * Supports formats:
     * - Plain seconds: "123", "45.5"
     * - MM:SS: "2:30"
     * - HH:MM:SS: "1:02:30"
     *
     * @return float|null Seconds, or null if parsing fails
     */
    public static function parseTimestamp(string $timestamp): ?float
    {
        $timestamp = trim($timestamp);

        if (str_contains($timestamp, ':')) {
            $parts = explode(':', $timestamp);

            if (count($parts) === 2) {
                // MM:SS
                $minutes = filter_var($parts[0], FILTER_VALIDATE_INT);
                $seconds = filter_var($parts[1], FILTER_VALIDATE_FLOAT);
                if ($minutes === false || $seconds === false) {
                    return null;
                }
                return (float) ($minutes * 60) + $seconds;
            }

            if (count($parts) === 3) {
                // HH:MM:SS
                $hours = filter_var($parts[0], FILTER_VALIDATE_INT);
                $minutes = filter_var($parts[1], FILTER_VALIDATE_INT);
                $seconds = filter_var($parts[2], FILTER_VALIDATE_FLOAT);
                if ($hours === false || $minutes === false || $seconds === false) {
                    return null;
                }
                return (float) ($hours * 3600 + $minutes * 60) + $seconds;
            }

            return null;
        }

        // Plain seconds
        $seconds = filter_var($timestamp, FILTER_VALIDATE_FLOAT);
        return $seconds !== false ? (float) $seconds : null;
    }
}
