def prs_decompress(data: bytes) -> bytes:
    out = bytearray(); pos = 0; ctrl = 0; bits = 0
    def bit():
        nonlocal ctrl, bits, pos
        if bits == 0:
            ctrl = data[pos]; pos += 1; bits = 8
        b = ctrl & 1; ctrl >>= 1; bits -= 1
        return b
    while True:
        if bit():
            out.append(data[pos]); pos += 1; continue
        if bit():
            lo = data[pos]; hi = data[pos + 1]; pos += 2
            off = (hi << 8) | lo
            if off == 0: break
            size = off & 7; off = (off >> 3) | -0x2000
            if size == 0:
                size = data[pos] + 1; pos += 1
            else:
                size += 2
        else:
            size = (bit() << 1) | bit(); size += 2
            off = data[pos] | -0x100; pos += 1
        start = len(out) + off
        for i in range(size):
            out.append(out[start + i])
    return bytes(out)
