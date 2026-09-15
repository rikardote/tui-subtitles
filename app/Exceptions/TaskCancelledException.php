<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando el usuario cancela una tarea en ejecución.
 * Permite distinguir una cancelación real de un error cualquiera
 * (antes se hacía comparando el texto del mensaje).
 */
final class TaskCancelledException extends RuntimeException
{
}
