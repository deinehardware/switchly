<a id="deutsch"></a>

> **Deutsch** | [English](#english)

# Projektstruktur

Die Verzeichnisse trennen öffentlich erreichbare Dateien konsequent von Anwendungscode, Deployment und Dokumentation.

```text
switchly/
├── public/                 Apache-Document-Root
│   ├── api/                HTTP-/JSON-Endpunkte
│   └── assets/             CSS, JavaScript, Bilder und lokale Bibliotheken
├── src/                    interne PHP-Services und Domänenlogik
├── bin/                    ausschließlich per CLI gestartete Worker
├── deploy/
│   ├── docker/             Apache-, PHP- und Entrypoint-Konfiguration
│   └── bare-metal/         Apache-, Umgebungs- und systemd-Vorlagen
├── docs/                   thematische Dokumentation
├── licenses/               Lizenztexte mitgelieferter Fremdkomponenten
├── scripts/                Entwicklungs- und Qualitätswerkzeuge
├── data/                   Platzhalter für lokale Bare-Metal-Daten
├── Dockerfile
├── docker-compose.yml
└── README.md
```

---

<a id="english"></a>

> [Deutsch](#deutsch) | **English**

<a id="en-project-structure"></a>

# Project structure

The directories consistently separate publicly accessible files from application code, deployment and documentation.

```text
switchly/
├── public/                 Apache document root
│   ├── api/                HTTP and JSON endpoints
│   └── assets/             CSS, JavaScript, images, and local libraries
├── src/                    Internal PHP services and domain logic
├── bin/                    Workers started exclusively through the CLI
├── deploy/
│   ├── docker/             Apache, PHP, and entry-point configuration
│   └── bare-metal/         Apache, environment, and systemd templates
├── docs/                   Topic-specific documentation
├── licenses/               License texts for bundled third-party components
├── scripts/                Development and quality-assurance tools
├── data/                   Placeholder for local bare-metal data
├── Dockerfile
├── docker-compose.yml
└── README.md
```
