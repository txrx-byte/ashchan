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
 * Video source type enum.
 *
 * Matches meguca's pb/nekotv.proto VideoType enum values.
 *
 * @see meguca/pb/nekotv.proto
 */
enum VideoType: int
{
    /** Raw HTML5 video (MP4/WebM from whitelisted domains). */
    case RAW = 0;

    /** YouTube regular video (IFrame Player API). */
    case YOUTUBE = 1;

    /** Twitch live stream (Twitch Embed API). */
    case TWITCH = 2;

    /** Generic iframe embed (YouTube Live, Kick). */
    case IFRAME = 3;

    /** TikTok video (via tikwm.com CDN proxy). */
    case TIKTOK = 4;

    /** TikTok live stream. */
    case TIKTOK_LIVE = 5;
}
