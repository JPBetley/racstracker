<?php

namespace App\LastWar\Game\Sfs;

/**
 * SFS2X data type tags, as they appear on the wire before each value.
 */
enum SfsType: int
{
    case Null = 0;
    case Bool = 1;
    case Byte = 2;
    case Short = 3;
    case Int = 4;
    case Long = 5;
    case Float = 6;
    case Double = 7;
    case UtfString = 8;
    case BoolArray = 9;
    case ByteArray = 10;
    case ShortArray = 11;
    case IntArray = 12;
    case LongArray = 13;
    case FloatArray = 14;
    case DoubleArray = 15;
    case UtfStringArray = 16;
    case Array = 17;
    case Object = 18;
    case Text = 20;
}
