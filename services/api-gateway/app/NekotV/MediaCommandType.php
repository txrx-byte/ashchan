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
 * Media command types parsed from post bodies.
 *
 * Port of meguca's common/commands.go MediaCommandType.
 *
 * @see meguca/common/commands.go
 */
enum MediaCommandType: int
{
    case ADD_VIDEO = 1;
    case REMOVE_VIDEO = 2;
    case SKIP_VIDEO = 3;
    case PAUSE = 4;
    case PLAY = 5;
    case SET_TIME = 6;
    case CLEAR_PLAYLIST = 7;
    case SET_RATE = 8;
}
