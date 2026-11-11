/**
 * The wake-word socket wants 16 kHz mono 16-bit PCM; a browser's microphone
 * gives 44.1 or 48 kHz floats. This resamples by averaging (which also filters
 * out what 16 kHz can't hold) and converts.
 */
export function toPcm16(input: Float32Array, inputRate: number, outputRate = 16000): Int16Array {
    if (inputRate < outputRate) {
        throw new Error('The microphone rate is lower than the target rate.');
    }
    const step = inputRate / outputRate;
    const out = new Int16Array(Math.floor(input.length / step));
    for (let i = 0; i < out.length; i++) {
        const from = Math.floor(i * step);
        const to = Math.min(input.length, Math.floor((i + 1) * step));
        let sum = 0;
        for (let j = from; j < to; j++) {
            sum += input[j];
        }
        const v = Math.max(-1, Math.min(1, sum / Math.max(1, to - from)));
        out[i] = v < 0 ? Math.round(v * 0x8000) : Math.round(v * 0x7fff);
    }

    return out;
}
