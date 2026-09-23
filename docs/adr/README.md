# Decyzje architektoniczne (ADR)

Każdy plik opisuje jedną decyzję: kontekst, wybór, konsekwencje i odrzucone warianty. Za pół roku ma się dać odtworzyć, **dlaczego** coś wygląda tak, a nie inaczej, bez archeologii w historii commitów.

## Zasady

- Jeden plik, jedna decyzja. Nazwa `NNNN-krotki-opis.md`, numeracja ciągła.
- ADR jest utrzymywany na bieżąco. Gdy ta sama decyzja zmienia się w szczegółach, treść jest edytowana tak, żeby opisywała decyzję obowiązującą, bez sekcji „korekta” i bez starej wartości obok nowej. Nowy ADR jest dla nowej decyzji albo zastąpionej w całości, ze statusem „Zastępuje ADR-XXXX”. Stary dostaje „Zastąpiony przez ADR-YYYY”.
- Wcześniejsza decyzja może zostać w krótkiej sekcji „Historia decyzji” na końcu, tylko gdy tłumaczy, dlaczego architektura wygląda tak, jak wygląda.
- Statusy: **Propozycja** → **Zaakceptowany** → **Zastąpiony** / **Odrzucony**. **Otwarty** oznacza decyzję świadomie odłożoną, z zapisanymi wariantami i kryterium rozstrzygnięcia.
- ADR zapisuje decyzję i jej powody. Jak funkcja się zachowuje, jest w `docs/spec/`.

## Rejestr

| ADR | Decyzja | Status | Data |
|---|---|---|---|
| [0001](0001-podmiana-klasy-command-zamiast-profilera.md) | Podmiana klasy `Command` zamiast profilera Yii | Zaakceptowany | 2026-09-22 |
| [0002](0002-plaska-lista-zamiast-agregatow.md) | Płaska lista zapytań zamiast agregatów | Zaakceptowany | 2026-09-22 |
| [0003](0003-finalizacja-w-after-request-i-shutdown.md) | Finalizacja w `EVENT_AFTER_REQUEST` z awaryjnym shutdown | Zaakceptowany | 2026-09-22 |
| [0004](0004-normalizacja-literalow-na-znak-zapytania.md) | Normalizacja literałów na `?`, przy niepewności `null` | Zaakceptowany | 2026-09-22 |
| [0005](0005-adapter-plikowy-z-blokada-i-utrata-paczki.md) | Adapter plikowy JSON Lines z blokadą nieblokującą | Zaakceptowany | 2026-09-22 |
| [0006](0006-blad-adaptera-gubi-paczke.md) | Błąd adaptera gubi paczkę, bez ponowień | Zaakceptowany | 2026-09-22 |
| [0007](0007-zadanie-konsolowe-to-jeden-proces.md) | Zadanie konsolowe to jedno uruchomienie procesu | Zaakceptowany | 2026-09-22 |
| [0008](0008-architektura-i-konwencje-testow.md) | Architektura testów i wersja PHPUnit | Zaakceptowany | 2026-09-22 |
| [0009](0009-caller-i-route-w-formacie-v2.md) | `caller` we wpisie i `route` w nagłówku, format `v: 2` | Zaakceptowany | 2026-09-23 |

Szablon: [`template.md`](template.md).
