<?php

namespace Laraclaw\Enums;

/**
 * Classification of embedded content by origin.
 */
enum MemorySourceType: string
{
    case Message = 'message';
    case Response = 'response';
    case Attachment = 'attachment';
}
