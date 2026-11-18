"""Build .pot/.po/.mo for SW Easy Variation Images.

Usage: python3 -I tools/build_i18n.py <plugin_dir>
Extracts every translatable string from the plugin PHP files, checks that
each one has a Spanish and a Russian translation, and writes languages/.
"""
import re
import struct
import sys
from pathlib import Path

DOMAIN = "sw-easy-variation-images"
NAME = "SW Easy Variation Images"
PHP_FILES = ["sw-easy-variation-images.php", "includes/class-sw-evi-core.php", "includes/class-sw-evi-admin.php"]

# Singular strings: msgid -> (es, ru)
TR = {
    "Set one image per attribute value (for example, per color) and every matching WooCommerce variation gets it. Compatible with the WoodMart variation gallery.": (
        "Asigna una imagen por valor de atributo (por ejemplo, por color) y todas las variaciones de WooCommerce con ese valor la reciben. Compatible con la galería de variaciones de WoodMart.",
        "Задайте одно фото для значения атрибута (например, для цвета) — и все вариации WooCommerce с этим значением получат его. Совместим с галереей вариаций WoodMart.",
    ),
    "Choose image": ("Elegir imagen", "Выбрать изображение"),
    "Choose additional images": ("Elegir imágenes adicionales", "Выбрать дополнительные изображения"),
    "Use this image": ("Usar esta imagen", "Использовать это изображение"),
    "Use these images": ("Usar estas imágenes", "Использовать эти изображения"),
    "There are unsaved changes in the variations. Save them first, then apply the images.": (
        "Hay cambios sin guardar en las variaciones. Guárdalos primero y después aplica las imágenes.",
        "В вариациях есть несохранённые изменения. Сначала сохраните их, затем примените изображения.",
    ),
    "Images chosen but not applied yet.": (
        "Imágenes elegidas, pero aún no aplicadas.",
        "Изображения выбраны, но ещё не применены.",
    ),
    "Applying…": ("Aplicando…", "Применяю…"),
    "Something went wrong. Reload the page and try again.": (
        "Algo ha fallado. Recarga la página e inténtalo de nuevo.",
        "Что-то пошло не так. Перезагрузите страницу и попробуйте ещё раз.",
    ),
    "Images by attribute": ("Imágenes por atributo", "Изображения по атрибуту"),
    "Share images by:": ("Compartir imágenes por:", "Общие изображения по:"),
    "Hide": ("Ocultar", "Скрыть"),
    "Show": ("Mostrar", "Показать"),
    "Image": ("Imagen", "Изображение"),
    "Additional images (WoodMart)": ("Imágenes adicionales (WoodMart)", "Доп. изображения (WoodMart)"),
    "Variations": ("Variaciones", "Вариации"),
    "Choose": ("Elegir", "Выбрать"),
    "Remove": ("Quitar", "Убрать"),
    "Apply to variations": ("Aplicar a las variaciones", "Применить к вариациям"),
    "Choose an image for each value and click “Apply to variations”: every variation with that value gets it, replacing its current image. New variations without an image get it automatically when you save them.": (
        "Elige una imagen para cada valor y pulsa «Aplicar a las variaciones»: todas las variaciones con ese valor la reciben y sustituye a su imagen actual. Las variaciones nuevas sin imagen la reciben automáticamente al guardarlas.",
        "Выберите изображение для каждого значения и нажмите «Применить к вариациям»: все вариации с этим значением получат его вместо текущего. Новые вариации без изображения получают его автоматически при сохранении.",
    ),
    "Done: all variations already had these images.": (
        "Hecho: todas las variaciones ya tenían estas imágenes.",
        "Готово: у всех вариаций уже были эти изображения.",
    ),
}

# Plural strings: (singular, plural) -> (es_forms[2], ru_forms[3])
TR_PLURAL = {
    (
        "%d variation uses “Any” for this attribute and is not changed.",
        "%d variations use “Any” for this attribute and are not changed.",
    ): (
        (
            "%d variación usa «Cualquiera» en este atributo y no se modifica.",
            "%d variaciones usan «Cualquiera» en este atributo y no se modifican.",
        ),
        (
            "%d вариация использует «Любое значение» для этого атрибута и не изменяется.",
            "%d вариации используют «Любое значение» для этого атрибута и не изменяются.",
            "%d вариаций используют «Любое значение» для этого атрибута и не изменяются.",
        ),
    ),
    ("Done: %d variation updated.", "Done: %d variations updated."): (
        ("Hecho: %d variación actualizada.", "Hecho: %d variaciones actualizadas."),
        ("Готово: обновлена %d вариация.", "Готово: обновлены %d вариации.", "Готово: обновлено %d вариаций."),
    ),
}

LOCALES = {
    "es_ES": (0, "nplurals=2; plural=(n != 1);"),
    "ru_RU": (1, "nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);"),
}

STR = r"'((?:[^'\\]|\\.)*)'"


def unescape(s: str) -> str:
    return s.replace("\\'", "'").replace("\\\\", "\\")


def extract(php: str):
    singles, plurals = [], []
    header = re.search(r"^\s*\*\s*Description:\s*(.+?)\s*$", php, re.M)
    if header:
        singles.append(header.group(1))
    pat = re.compile(r"(?:esc_html__|esc_html_e|esc_attr__|esc_attr_e|__|_e)\(\s*" + STR + r"\s*,\s*'([^']+)'\s*\)")
    for m in pat.finditer(php):
        if m.group(2) == DOMAIN:
            singles.append(unescape(m.group(1)))
    pat_n = re.compile(r"_n\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,\s*[^,]+,\s*'([^']+)'\s*\)")
    for m in pat_n.finditer(php):
        if m.group(3) == DOMAIN:
            plurals.append((unescape(m.group(1)), unescape(m.group(2))))
    return singles, plurals


def po_escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def write_po(path: Path, header: str, singles, plurals, nforms):
    lines = ['msgid ""', 'msgstr ""']
    lines += [f'"{po_escape(h)}\\n"' for h in header.strip().split("\n")]
    lines.append("")
    for msgid, msgstr in singles:
        if "%" in msgid:
            lines.append("#, php-format")
        lines.append(f'msgid "{po_escape(msgid)}"')
        lines.append(f'msgstr "{po_escape(msgstr)}"')
        lines.append("")
    for (one, many), forms in plurals:
        lines.append("#, php-format")
        lines.append(f'msgid "{po_escape(one)}"')
        lines.append(f'msgid_plural "{po_escape(many)}"')
        for i in range(nforms):
            lines.append(f'msgstr[{i}] "{po_escape(forms[i] if forms else "")}"')
        lines.append("")
    path.write_text("\n".join(lines), encoding="utf-8")


def write_mo(path: Path, header: str, singles, plurals):
    entries = [("", header.strip() + "\n")]
    entries += singles
    entries += [(one + "\0" + many, "\0".join(forms)) for (one, many), forms in plurals]
    entries.sort(key=lambda e: e[0].encode("utf-8"))
    ids = [k.encode("utf-8") for k, _ in entries]
    strs = [v.encode("utf-8") for _, v in entries]
    n = len(entries)
    o_ids = 7 * 4
    o_strs = o_ids + n * 8
    data_start = o_strs + n * 8
    id_table, str_table, blob = [], [], b""
    for b in ids:
        id_table.append((len(b), data_start + len(blob)))
        blob += b + b"\0"
    for b in strs:
        str_table.append((len(b), data_start + len(blob)))
        blob += b + b"\0"
    out = struct.pack("<7I", 0x950412DE, 0, n, o_ids, o_strs, 0, 0)
    for l, o in id_table:
        out += struct.pack("<2I", l, o)
    for l, o in str_table:
        out += struct.pack("<2I", l, o)
    path.write_bytes(out + blob)


def placeholders(s: str):
    return sorted(re.findall(r"%(?:\d+\$)?[sd]", s))


def main():
    plugin = Path(sys.argv[1])
    singles, plurals = [], []
    for name in PHP_FILES:
        s, p = extract((plugin / name).read_text(encoding="utf-8"))
        singles += [x for x in s if x not in singles]
        plurals += [x for x in p if x not in plurals]

    missing = [s for s in singles if s not in TR] + [" | ".join(p) for p in plurals if p not in TR_PLURAL]
    if missing:
        sys.exit("Missing translations:\n" + "\n".join(missing))
    unused = [s for s in TR if s not in singles] + [p for p in TR_PLURAL if p not in plurals]
    if unused:
        print("Note: unused entries:", unused)

    for s, (es, ru) in TR.items():
        for t in (es, ru):
            if placeholders(s) != placeholders(t):
                sys.exit(f"Placeholder mismatch: {s!r} -> {t!r}")
    for (one, many), (es, ru) in TR_PLURAL.items():
        if len(es) != 2 or len(ru) != 3:
            sys.exit(f"Wrong number of plural forms: {one!r}")
        for t in es + ru:
            if placeholders(one) != placeholders(t):
                sys.exit(f"Placeholder mismatch: {one!r} -> {t!r}")

    langdir = plugin / "languages"
    langdir.mkdir(exist_ok=True)
    base = (
        f"Project-Id-Version: {NAME}\n"
        "MIME-Version: 1.0\n"
        "Content-Type: text/plain; charset=UTF-8\n"
        "Content-Transfer-Encoding: 8bit\n"
    )
    pot_header = base + "Plural-Forms: nplurals=2; plural=(n != 1);\n" + f"X-Domain: {DOMAIN}"
    write_po(langdir / f"{DOMAIN}.pot", pot_header, [(s, "") for s in singles], [(p, None) for p in plurals], 2)

    for loc, (idx, plural) in LOCALES.items():
        header = base.replace("MIME-Version", f"Language: {loc}\nMIME-Version") + f"Plural-Forms: {plural}\n" + f"X-Domain: {DOMAIN}"
        s_pairs = [(s, TR[s][idx]) for s in singles]
        p_pairs = [(p, TR_PLURAL[p][idx]) for p in plurals]
        nforms = 2 if idx == 0 else 3
        write_po(langdir / f"{DOMAIN}-{loc}.po", header, s_pairs, p_pairs, nforms)
        write_mo(langdir / f"{DOMAIN}-{loc}.mo", header, s_pairs, p_pairs)

    print(f"{len(singles)} strings + {len(plurals)} plurals, locales: {', '.join(LOCALES)}")


if __name__ == "__main__":
    main()
