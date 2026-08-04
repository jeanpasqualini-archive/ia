<?php

declare(strict_types=1);

namespace InputController;

enum MouseAction
{
    /** A button went down: where a drag starts from. */
    case Press;

    /** Moved with a button held. */
    case Drag;

    /** The button came back up, whatever it was doing. */
    case Release;

    case ScrollUp;

    case ScrollDown;
}
