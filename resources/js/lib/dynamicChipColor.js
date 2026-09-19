/*
 | A stage's colour comes from the database — CrmTaxonomy::stageColors(), an
 | admin can pick anything — so StageBadge, the activity timeline's icon tint,
 | the stage-form preview and the cross-filter chips all draw it as a straight
 | hex value with no palette this stylesheet controls. On a white card that
 | plain hex read as text and, at low alpha, as its own background works for
 | almost any colour an admin might reasonably pick. On a near-black card the
 | same rule does not hold: a colour picked to read on white can be too dark to
 | read on slate-900, and the same low-alpha wash all but disappears into it.
 |
 | This is the fix for that, and it is deliberately not "invert the colour" —
 | inverting a stage's own hue would mean Fresh and Connected no longer being
 | the same colour they are everywhere else in the app. Instead: keep the hue
 | and saturation exactly as chosen, lift the LIGHTNESS used for the text far
 | enough that it reads on a dark surface, and raise the background's alpha so
 | the tint still registers as a tint rather than as nothing.
 */

function hexToRgb(hex) {
    const clean = hex.replace('#', '')
    const full = clean.length === 3 ? clean.split('').map(c => c + c).join('') : clean
    const int = parseInt(full, 16)

    return { r: (int >> 16) & 255, g: (int >> 8) & 255, b: int & 255 }
}

function rgbToHsl({ r, g, b }) {
    r /= 255; g /= 255; b /= 255

    const max = Math.max(r, g, b)
    const min = Math.min(r, g, b)
    let h = 0
    let s = 0
    const l = (max + min) / 2

    if (max !== min) {
        const d = max - min
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min)

        switch (max) {
            case r: h = (g - b) / d + (g < b ? 6 : 0); break
            case g: h = (b - r) / d + 2; break
            default: h = (r - g) / d + 4
        }
        h /= 6
    }

    return { h: h * 360, s: s * 100, l: l * 100 }
}

/**
 * The style object every stage-coloured chip binds to `:style`.
 *
 * @param {string} hex the stage's own colour, e.g. "#0F766E"
 * @param {boolean} isDark
 * @returns {{ color: string, backgroundColor: string }}
 */
export function chipStyle(hex, isDark) {
    if (!isDark) {
        return { color: hex, backgroundColor: hex + '18' }
    }

    const { h, s, l } = rgbToHsl(hexToRgb(hex))

    // never darkens an already-light colour, only lifts a dark one enough to
    // clear a comfortable contrast floor against a near-black card
    const textLightness = Math.max(l, 62)

    return {
        color: `hsl(${h.toFixed(1)} ${s.toFixed(1)}% ${textLightness.toFixed(1)}%)`,
        backgroundColor: `hsla(${h.toFixed(1)} ${s.toFixed(1)}% ${l.toFixed(1)}% / 0.22)`,
    }
}

/**
 * The bordered variant — CrossFilterChips' stage chip — same lift, plus a
 * border a shade more opaque than the fill so the outline still reads once
 * the fill itself is nearly the same colour as the card behind it.
 *
 * @param {string} hex
 * @param {boolean} isDark
 * @returns {{ color: string, borderColor: string, backgroundColor: string }}
 */
export function chipOutlineStyle(hex, isDark) {
    if (!isDark) {
        return { color: hex, borderColor: hex + '55', backgroundColor: hex + '14' }
    }

    const { color, backgroundColor } = chipStyle(hex, isDark)
    const { h, s, l } = rgbToHsl(hexToRgb(hex))

    return { color, backgroundColor, borderColor: `hsla(${h.toFixed(1)} ${s.toFixed(1)}% ${l.toFixed(1)}% / 0.45)` }
}
