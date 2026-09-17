#!/usr/bin/env python3
"""Generate the deterministic, stylized V1 avatar component library."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ASSET_ROOT = ROOT / "game" / "assets" / "avatar" / "v1"


def svg(body: str) -> str:
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" width="512" height="512" '
        'viewBox="0 0 512 512"><g>' + body + "</g></svg>\n"
    )


def write_component(folder: str, asset_id: str, body: str) -> str:
    relative = Path("svg") / folder / (asset_id.replace(".", "_") + ".svg")
    path = ASSET_ROOT / relative
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(svg(body), encoding="utf-8")
    return relative.as_posix()


def asset(asset_id: str, category: str, family: str, label: str, folder: str,
          body: str, tint: str = "none", tags: list[str] | None = None,
          npc_weight: int = 1) -> dict:
    return {
        "id": asset_id,
        "version": 1,
        "category": category,
        "family": family,
        "label": label,
        "layers": [category],
        "tint_mode": tint,
        "creator_enabled": True,
        "creator_order": 0,
        "npc_enabled": True,
        "npc_weight": npc_weight,
        "compatibility": {"schema_version": 1, "requires": [], "excludes": []},
        "tags": tags or [],
        "deprecated": False,
        "replacement_id": None,
        "file": write_component(folder, asset_id, body),
    }


def path_face(rx: int, ry: int, cy: int = 274, tilt: int = 0) -> str:
    return f'<ellipse cx="256" cy="{cy}" rx="{rx}" ry="{ry}" transform="rotate({tilt} 256 {cy})" fill="{{BASE}}" stroke="{{DARK}}" stroke-width="5"/>'


def path_hair(family: str, variant: int) -> str:
    x = 256 + (variant - 2) * 3
    if family == "bald":
        return '<path d="M143 218 Q154 102 256 94 Q358 102 369 218" fill="none" stroke="{DARK}" stroke-width="8"/>'
    if family == "shaved":
        return '<path d="M140 230 Q148 115 256 105 Q364 115 372 230 L350 198 Q330 140 256 136 Q182 140 162 198Z" fill="{HAIR}" stroke="{DARK}" stroke-width="5" opacity=".82"/>'
    if family == "straight":
        return f'<path d="M132 270 Q132 103 {x} 91 Q380 103 380 270 L348 242 L330 152 Q256 126 182 152 L164 242Z" fill="{{HAIR}}" stroke="{{DARK}}" stroke-width="5"/><path d="M181 148 Q256 123 331 148" fill="none" stroke="{{HIGHLIGHT}}" stroke-width="7"/>'
    if family == "wavy":
        return '<path d="M126 260 Q120 166 160 119 Q196 75 256 96 Q316 75 352 119 Q392 166 386 260 Q358 221 338 164 Q299 128 256 145 Q213 128 174 164 Q154 221 126 260Z" fill="{HAIR}" stroke="{DARK}" stroke-width="5"/><path d="M155 160 Q190 120 224 141 M288 141 Q322 120 357 160" fill="none" stroke="{HIGHLIGHT}" stroke-width="8"/>'
    if family == "curly":
        circles = "".join(f'<circle cx="{110 + i * 31}" cy="{155 + (i % 2) * 16}" r="35" fill="{{HAIR}}" stroke="{{DARK}}" stroke-width="5"/>' for i in range(9))
        return circles + '<path d="M122 193 Q256 78 390 193 L367 261 Q333 163 256 151 Q179 163 145 261Z" fill="{HAIR}" stroke="{DARK}" stroke-width="5"/>'
    if family == "coily":
        dots = "".join(f'<circle cx="{145 + (i % 6) * 44}" cy="{121 + (i // 6) * 34}" r="15" fill="{{HAIR}}" stroke="{{DARK}}" stroke-width="4"/>' for i in range(24))
        return dots + '<path d="M137 222 Q143 84 256 84 Q369 84 375 222 Q348 161 256 151 Q164 161 137 222Z" fill="none" stroke="{DARK}" stroke-width="8"/>'
    if family == "afro":
        dots = "".join(f'<circle cx="{130 + (i % 7) * 42}" cy="{112 + (i // 7) * 36}" r="31" fill="{{HAIR}}" stroke="{{DARK}}" stroke-width="5"/>' for i in range(28))
        return dots
    if family == "braids":
        braids = "".join(f'<path d="M{145 + i * 22} 116 Q{130 + i * 24} 170 {145 + i * 22} 260" fill="none" stroke="{{HAIR}}" stroke-width="13" stroke-linecap="round"/><path d="M{145 + i * 22} 116 Q{130 + i * 24} 170 {145 + i * 22} 260" fill="none" stroke="{{HIGHLIGHT}}" stroke-width="3"/>' for i in range(11))
        return braids
    if family == "cornrows":
        rows = "".join(f'<path d="M145 {105 + i * 18} Q256 {78 + i * 12} 367 {105 + i * 18}" fill="none" stroke="{{HAIR}}" stroke-width="13" stroke-linecap="round"/>' for i in range(7))
        return rows
    if family == "locs":
        locs = "".join(f'<path d="M{148 + i * 24} 110 Q{125 + i * 20} {160 + (i % 3) * 12} {145 + i * 24} 274" fill="none" stroke="{{HAIR}}" stroke-width="17" stroke-linecap="round"/>' for i in range(10))
        return locs
    if family == "bun":
        return '<circle cx="256" cy="72" r="52" fill="{HAIR}" stroke="{DARK}" stroke-width="5"/><path d="M139 245 Q125 112 256 96 Q387 112 373 245 L340 174 Q256 124 172 174Z" fill="{HAIR}" stroke="{DARK}" stroke-width="5"/>'
    if family == "ponytail":
        return '<path d="M347 142 Q454 143 412 285 Q381 330 347 270Z" fill="{HAIR}" stroke="{DARK}" stroke-width="5"/><path d="M132 260 Q124 110 256 94 Q388 110 380 260 L345 178 Q256 127 167 178Z" fill="{HAIR}" stroke="{DARK}" stroke-width="5"/>'
    return '<path d="M132 260 Q124 110 256 94 Q388 110 380 260 L345 178 Q256 127 167 178Z" fill="{HAIR}" stroke="{DARK}" stroke-width="5"/>'


def make_library() -> tuple[list[dict], dict, list[dict], dict]:
    assets: list[dict] = []
    families = ["oval", "round", "square", "long", "heart", "diamond", "soft", "angular", "youthful", "mature", "broad", "narrow"]
    face_sizes = [(126, 163), (139, 153), (132, 164), (116, 174), (128, 158), (121, 168), (135, 160), (124, 171), (130, 155), (134, 166), (145, 151), (113, 169)]
    for i, (family, (rx, ry)) in enumerate(zip(families, face_sizes), 1):
        assets.append(asset(f"avatar.face.{family}.{i:02d}", "face", family, family.title(), "face", path_face(rx, ry, 274, (i % 3) - 1), "skin"))

    jaw_families = ["soft", "defined", "square", "tapered", "wide", "pointed", "strong", "rounded"]
    for i, family in enumerate(jaw_families, 1):
        width = 90 + i * 4
        assets.append(asset(f"avatar.jaw.{family}.{i:02d}", "jaw", family, family.title(), "jaw", f'<path d="M{256-width} 322 Q256 {390 + (i % 3) * 5} {256+width} 322" fill="none" stroke="{{SHADOW}}" stroke-width="{3 + i % 3}" stroke-linecap="round"/>', "skin"))

    for i, family in enumerate(["small", "medium", "large", "round", "pointed", "close"], 1):
        rx, ry = 19 + i, 31 + i * 2
        assets.append(asset(f"avatar.ears.{family}.{i:02d}", "ears", family, family.title(), "ears", f'<ellipse cx="{145 - i}" cy="276" rx="{rx}" ry="{ry}" fill="{{BASE}}" stroke="{{DARK}}" stroke-width="4"/><ellipse cx="{367 + i}" cy="276" rx="{rx}" ry="{ry}" fill="{{BASE}}" stroke="{{DARK}}" stroke-width="4"/><path d="M{145-i} 266 q{10+i} 10 0 24 M{367+i} 266 q-{10+i} 10 0 24" fill="none" stroke="{{SHADOW}}" stroke-width="4"/>', "skin"))

    eye_families = ["almond", "round", "hooded", "upturned", "downturned", "wide", "deep", "monolid", "sleepy", "sharp", "soft", "large", "close", "focused"]
    for i, family in enumerate(eye_families, 1):
        ry = 11 + (i % 4) * 2
        tilt = (i % 5) - 2
        assets.append(asset(f"avatar.eyes.{family}.{i:02d}", "eyes", family, family.title(), "eyes", f'<g stroke="{{DARK}}" stroke-width="6" fill="{{EYE_WHITE}}"><ellipse cx="205" cy="248" rx="{35 + i % 4 * 2}" ry="{ry}" transform="rotate({tilt} 205 248)"/><ellipse cx="307" cy="248" rx="{35 + i % 4 * 2}" ry="{ry}" transform="rotate({-tilt} 307 248)"/></g><g fill="{{EYE}}"><circle cx="205" cy="248" r="9"/><circle cx="307" cy="248" r="9"/></g><g fill="{{DARK}}"><circle cx="205" cy="248" r="4"/><circle cx="307" cy="248" r="4"/></g>', "eye"))

    for i, family in enumerate(["straight", "arched", "soft", "thick", "short", "high", "low", "angled", "rounded", "bold", "fine", "wide", "close", "expressive"], 1):
        lift = (i % 5) * 3
        assets.append(asset(f"avatar.brows.{family}.{i:02d}", "brows", family, family.title(), "brows", f'<path d="M164 {211-lift} Q205 {190-lift-(i%3)*3} 239 {211-lift}" fill="none" stroke="{{HAIR}}" stroke-width="{7 + i % 4}" stroke-linecap="round"/><path d="M273 {211-lift} Q307 {190-lift-(i%3)*3} 348 {211-lift}" fill="none" stroke="{{HAIR}}" stroke-width="{7 + i % 4}" stroke-linecap="round"/>', "hair"))

    nose_families = ["straight", "button", "wide", "slender", "roman", "short", "long", "rounded", "angular", "soft", "high", "low", "compact", "distinct"]
    for i, family in enumerate(nose_families, 1):
        w = 11 + i % 5 * 2
        assets.append(asset(f"avatar.nose.{family}.{i:02d}", "nose", family, family.title(), "nose", f'<path d="M256 248 Q{244-w} {292+i%4*4} 256 316 Q{268+w} {292+i%4*4} 256 316" fill="none" stroke="{{SHADOW}}" stroke-width="5" stroke-linecap="round"/><path d="M{248-w//2} 316 Q256 {322+i%3} {264+w//2} 316" fill="none" stroke="{{DARK}}" stroke-width="4" stroke-linecap="round"/>', "skin"))

    mouth_families = ["neutral", "smile", "full", "thin", "wide", "soft", "defined", "upturned", "downturned", "open", "bow", "compact"]
    for i, family in enumerate(mouth_families, 1):
        curve = (i % 5) - 2
        opening = '<path d="M222 350 Q256 366 290 350 Q256 386 222 350Z" fill="{DARK}"/>' if family == "open" else f'<path d="M216 354 Q256 {350+curve*4} 296 354 Q256 {370+curve*2} 216 354Z" fill="{{MOUTH}}" stroke="{{DARK}}" stroke-width="4"/>'
        assets.append(asset(f"avatar.mouth.{family}.{i:02d}", "mouth", family, family.title(), "mouth", opening, "mouth"))

    hair_families = ["bald", "shaved", "straight", "wavy", "curly", "coily", "afro", "braids", "cornrows", "locs", "bun", "ponytail"]
    hair_tags = {"bald": ["bald"], "shaved": ["shaved"], "straight": ["straight"], "wavy": ["wavy"], "curly": ["curly"], "coily": ["coily"], "afro": ["afro"], "braids": ["braids", "protective"], "cornrows": ["cornrows", "protective"], "locs": ["locs", "protective"], "bun": ["bun"], "ponytail": ["ponytail"]}
    for family in hair_families:
        for variant in range(1, 5):
            assets.append(asset(f"avatar.hair.{family}.{variant:02d}", "hair", family, f"{family.title()} {variant}", f"hair/{'protective' if family in {'braids','cornrows','locs'} else family}", path_hair(family, variant), "hair", hair_tags[family]))

    beard_families = ["none", "stubble", "short", "full", "goatee", "chin", "mustache", "mustache_full", "boxed", "rounded", "light", "heavy", "trimmed", "sideburns", "soul_patch", "van_dyke", "short_boxed", "long", "pointed", "five_oclock"]
    for i, family in enumerate(beard_families, 1):
        if family == "none":
            body = ""
        elif family in {"mustache", "soul_patch", "sideburns"}:
            body = '<path d="M220 333 Q256 347 292 333 Q280 352 256 350 Q232 352 220 333Z" fill="{HAIR}" opacity=".9"/>'
        else:
            body = f'<path d="M{178+i%4*4} 315 Q256 {405+i%3*5} {334-i%4*4} 315 Q320 380 256 388 Q192 380 {178+i%4*4} 315Z" fill="{{HAIR}}" opacity="{0.28 + (i % 5) * 0.12:.2f}" stroke="{{DARK}}" stroke-width="3"/>'
        assets.append(asset(f"avatar.beard.{family}.{i:02d}", "facial_hair", family, family.replace("_", " ").title(), "beard", body, "hair"))

    detail_families = ["none", "freckles", "cheek_freckles", "beauty_mark", "under_eye", "sun_kissed", "soft_blush", "small_mark", "dimples", "warm_cheeks", "light_texture", "distinct_mark"]
    for i, family in enumerate(detail_families, 1):
        body = "" if family == "none" else f'<g fill="{{DETAIL}}" opacity="{0.25 + (i % 4) * 0.12:.2f}"><circle cx="{190+i%3*12}" cy="294" r="3"/><circle cx="{322-i%3*12}" cy="294" r="3"/><circle cx="{205+i%2*8}" cy="305" r="2"/><circle cx="{307-i%2*8}" cy="305" r="2"/></g>'
        assets.append(asset(f"avatar.details.{family}.{i:02d}", "skin_detail", family, family.replace("_", " ").title(), "details", body, "skin"))

    for i, family in enumerate(["none", "brow", "cheek", "lip", "chin", "temple", "small", "long"], 1):
        body = "" if family == "none" else f'<path d="M{190+i*5} {220+i*10} q{18+i} {8+i} {30+i} 0" fill="none" stroke="{{SCAR}}" stroke-width="{2+i%3}" stroke-linecap="round"/>'
        assets.append(asset(f"avatar.scar.{family}.{i:02d}", "scar", family, family.title(), "scars", body, "scar"))

    accessory_families = ["none", "glasses_round", "glasses_square", "headband", "earring_left", "earring_pair", "cap", "neck_chain"]
    for i, family in enumerate(accessory_families, 1):
        body = ""
        if family == "glasses_round": body = '<g fill="none" stroke="{ACCESSORY}" stroke-width="6"><circle cx="205" cy="248" r="35"/><circle cx="307" cy="248" r="35"/><path d="M240 248 H272"/></g>'
        if family == "glasses_square": body = '<g fill="none" stroke="{ACCESSORY}" stroke-width="6"><rect x="166" y="220" width="76" height="53" rx="12"/><rect x="270" y="220" width="76" height="53" rx="12"/><path d="M242 246 H270"/></g>'
        if family == "headband": body = '<path d="M137 178 Q256 90 375 178" fill="none" stroke="{ACCESSORY}" stroke-width="13"/>'
        if family == "earring_left": body = '<circle cx="142" cy="306" r="9" fill="{ACCESSORY}"/>'
        if family == "earring_pair": body = '<circle cx="142" cy="306" r="9" fill="{ACCESSORY}"/><circle cx="370" cy="306" r="9" fill="{ACCESSORY}"/>'
        if family == "cap": body = '<path d="M132 168 Q256 74 380 168 L367 190 Q256 145 145 190Z" fill="{ACCESSORY}" stroke="{DARK}" stroke-width="5"/>'
        if family == "neck_chain": body = '<path d="M196 401 Q256 435 316 401" fill="none" stroke="{ACCESSORY}" stroke-width="6"/><circle cx="256" cy="421" r="9" fill="{ACCESSORY}"/>'
        assets.append(asset(f"avatar.accessory.{family}.{i:02d}", "accessory", family, family.replace("_", " ").title(), "accessories", body, "accessory"))

    for i, family in enumerate(["youth", "young", "mature", "veteran", "late"], 1):
        body = "" if i < 3 else f'<g fill="none" stroke="{{AGE}}" stroke-width="{2+i%2}" opacity=".45"><path d="M205 {285+i*3} q12 8 24 0 M283 {285+i*3} q12 8 24 0"/><path d="M210 {326+i*3} q10 5 20 0 M282 {326+i*3} q10 5 20 0"/></g>'
        assets.append(asset(f"avatar.age.{family}.{i:02d}", "age", family, family.title(), "age", body, "age"))

    for i, family in enumerate(["slim", "average", "athletic", "broad"], 1):
        width = [72, 88, 82, 104][i - 1]
        assets.append(asset(f"avatar.body.{family}.{i:02d}", "body", family, family.title(), "body", f'<path d="M256 390 Q{256-width} 396 {256-width-12} 512 H{256+width+12} Q{256+width} 396 256 390Z" fill="{{BODY}}" stroke="{{DARK}}" stroke-width="5"/>', "body"))

    kit_families = ["solid", "vertical_stripes", "horizontal_stripes", "halves", "sash", "shoulders", "sleeves", "center_stripe", "gradient", "pinstripe", "chevron", "hoops"]
    for i, family in enumerate(kit_families, 1):
        if family == "solid": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/>'
        elif family == "vertical_stripes": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M195 392 V512 H235 V392Z M277 392 V512 H317 V392Z" fill="{KIT_SECONDARY}"/>'
        elif family == "horizontal_stripes": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M128 430 H384 L374 462 H138Z M138 480 H374 L366 512 H146Z" fill="{KIT_SECONDARY}"/>'
        elif family == "halves": body = '<path d="M170 390 H256 V512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M256 390 H342 L402 430 L370 512 H256Z" fill="{KIT_SECONDARY}"/>'
        elif family == "sash": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M174 390 L350 492 L330 512 L155 410Z" fill="{KIT_SECONDARY}"/>'
        elif family == "shoulders": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M170 390 H342 L365 406 H147Z" fill="{KIT_SECONDARY}"/>'
        elif family == "sleeves": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M110 430 L170 390 L183 410 L128 453Z M342 390 L402 430 L384 453 L329 410Z" fill="{KIT_SECONDARY}"/>'
        elif family == "center_stripe": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M243 390 H269 V512 H243Z" fill="{KIT_SECONDARY}"/>'
        elif family == "gradient": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M142 512 H370 L385 470 H127Z" fill="{KIT_SECONDARY}" opacity=".75"/>'
        elif family == "pinstripe": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M210 392 V512 M230 392 V512 M282 392 V512 M302 392 V512" stroke="{KIT_SECONDARY}" stroke-width="7"/>'
        elif family == "chevron": body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M142 430 L256 476 L370 430 L358 460 L256 500 L154 460Z" fill="{KIT_SECONDARY}"/>'
        else: body = '<path d="M170 390 H342 L402 430 L370 512 H142 L110 430Z" fill="{KIT_PRIMARY}" stroke="{KIT_DARK}" stroke-width="5"/><path d="M128 430 H384 V454 H128Z M136 476 H376 V500 H136Z" fill="{KIT_SECONDARY}"/>'
        assets.append(asset(f"avatar.kit.{family}.{i:02d}", "kit", family, family.replace("_", " ").title(), "kit", body, "kit"))

    for i, family in enumerate(["neutral", "club", "matchday", "transfer", "news", "career"], 1):
        assets.append(asset(f"avatar.background.{family}.{i:02d}", "background", family, family.title(), "backgrounds", '<rect width="512" height="512" fill="{BACKGROUND}"/><path d="M0 410 Q160 350 320 410 T640 410 V512 H0Z" fill="{BACKGROUND_ACCENT}" opacity=".45"/>', "background"))

    for i, family in enumerate(["neutral", "happy", "focused", "disappointed", "celebrating"], 1):
        mouth = {"neutral": "M228 354 Q256 358 284 354", "happy": "M220 350 Q256 382 292 350", "focused": "M226 355 H286", "disappointed": "M220 365 Q256 340 292 365", "celebrating": "M220 348 Q256 392 292 348"}[family]
        assets.append(asset(f"avatar.expression.{family}.{i:02d}", "expression", family, family.title(), "expression", f'<path d="{mouth}" fill="none" stroke="{{EXPRESSION}}" stroke-width="6" stroke-linecap="round"/>', "expression"))

    skins = []
    skin_values = [("F2D1B3", "D29B78", "FFE7CE"), ("E8B894", "B97958", "F5D1B2"), ("D99A70", "A96749", "F0B58B"), ("C9865A", "945331", "E3AA7C"), ("B96F48", "7D412A", "D79561"), ("A65D3B", "703521", "C98258"), ("92502F", "5E2E1B", "B86E45"), ("7D4329", "4D2518", "A65D3A"), ("67351F", "3E1D14", "8E4C2C"), ("502718", "2E140D", "79412B"), ("3C1B12", "24100B", "5D2B1A"), ("2B130D", "190A06", "4B2115")]
    for i, (base, shadow, highlight) in enumerate(skin_values, 1):
        skins.append({"id": f"palette.skin.{i:02d}", "label": f"Skin tone {i}", "base": f"#{base}", "shadow": f"#{shadow}", "highlight": f"#{highlight}"})
    eyes = [{"id": "palette.eye.brown", "label": "Brown", "color": "#4A291A"}, {"id": "palette.eye.dark_brown", "label": "Dark Brown", "color": "#24140D"}, {"id": "palette.eye.hazel", "label": "Hazel", "color": "#927348"}, {"id": "palette.eye.green", "label": "Green", "color": "#4C7A58"}, {"id": "palette.eye.blue", "label": "Blue", "color": "#527FA2"}, {"id": "palette.eye.grey", "label": "Grey", "color": "#7C8790"}, {"id": "palette.eye.amber", "label": "Amber", "color": "#B8792F"}, {"id": "palette.eye.dark_grey", "label": "Dark Grey", "color": "#3C4B55"}]
    hairs = ["#161311", "#2A1A15", "#43271B", "#5B3421", "#77482B", "#91613A", "#B27A48", "#D0A16B", "#D6B979", "#B6B1A4", "#E1E0DA", "#F0E7D2"]
    hair_palette = [{"id": f"palette.hair.{i:02d}", "label": f"Hair color {i}", "base": color, "shadow": "#17120F", "highlight": "#E7C08A"} for i, color in enumerate(hairs, 1)]
    palettes = {"skin": skins, "eye": eyes, "hair": hair_palette}

    fields = ["skin_tone", "face", "jaw", "ears", "eyes", "eye_color", "brows", "nose", "mouth", "hair", "hair_color", "facial_hair", "facial_hair_color", "skin_detail", "scar", "accessory"]
    presets = []
    face_ids = [a["id"] for a in assets if a["category"] == "face"]
    jaw_ids = [a["id"] for a in assets if a["category"] == "jaw"]
    ear_ids = [a["id"] for a in assets if a["category"] == "ears"]
    eye_ids = [a["id"] for a in assets if a["category"] == "eyes"]
    brow_ids = [a["id"] for a in assets if a["category"] == "brows"]
    nose_ids = [a["id"] for a in assets if a["category"] == "nose"]
    mouth_ids = [a["id"] for a in assets if a["category"] == "mouth"]
    hair_ids = [a["id"] for a in assets if a["category"] == "hair"]
    beard_ids = [a["id"] for a in assets if a["category"] == "facial_hair"]
    detail_ids = [a["id"] for a in assets if a["category"] == "skin_detail"]
    scar_ids = [a["id"] for a in assets if a["category"] == "scar"]
    accessory_ids = [a["id"] for a in assets if a["category"] == "accessory"]
    for i in range(16):
        presets.append({"id": f"preset.v1.{i+1:02d}", "label": ["Classic", "Emerging", "Captain", "Creative", "Quick", "Finisher", "Playmaker", "Defender", "Keeper", "Street", "Academy", "Veteran", "Focused", "Bright", "Calm", "Bold"][i], "appearance": {"skin_tone": skins[(i * 3) % 12]["id"], "face": face_ids[i % len(face_ids)], "jaw": jaw_ids[i % len(jaw_ids)], "ears": ear_ids[i % len(ear_ids)], "eyes": eye_ids[i % len(eye_ids)], "eye_color": eyes[i % len(eyes)]["id"], "brows": brow_ids[i % len(brow_ids)], "nose": nose_ids[i % len(nose_ids)], "mouth": mouth_ids[i % len(mouth_ids)], "hair": hair_ids[(i * 3 + 2) % len(hair_ids)], "hair_color": hair_palette[(i * 2) % len(hair_palette)]["id"], "facial_hair": beard_ids[i % len(beard_ids)], "facial_hair_color": hair_palette[(i * 2 + 1) % len(hair_palette)]["id"], "skin_detail": detail_ids[i % len(detail_ids)], "scar": scar_ids[i % len(scar_ids)], "accessory": accessory_ids[i % len(accessory_ids)]}})

    for item in assets:
        item["creator_order"] = ["face", "jaw", "ears", "eyes", "brows", "nose", "mouth", "hair", "facial_hair", "skin_detail", "scar", "accessory", "age", "body", "kit", "background", "expression"].index(item["category"])
    return assets, palettes, presets, {"schema_version": 1, "id_pattern": "avatar.<category>.<family>.<variant>", "palette_pattern": "palette.<category>.<variant>", "stable_fields": fields[:9], "mutable_fields": fields[9:]}


def main() -> None:
    assets, palettes, presets, compatibility = make_library()
    (ASSET_ROOT / "catalog").mkdir(parents=True, exist_ok=True)
    (ASSET_ROOT / "catalog" / "assets.json").write_text(json.dumps({"schema_version": 1, "assets": assets}, indent=2) + "\n", encoding="utf-8")
    (ASSET_ROOT / "catalog" / "palettes.json").write_text(json.dumps({"schema_version": 1, "palettes": palettes}, indent=2) + "\n", encoding="utf-8")
    (ASSET_ROOT / "catalog" / "presets.json").write_text(json.dumps({"schema_version": 1, "presets": presets}, indent=2) + "\n", encoding="utf-8")
    (ASSET_ROOT / "catalog" / "compatibility.json").write_text(json.dumps(compatibility, indent=2) + "\n", encoding="utf-8")
    print(f"generated {len(assets)} SVG assets, {sum(map(len, palettes.values()))} palettes, {len(presets)} presets")


if __name__ == "__main__":
    main()
