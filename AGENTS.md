# AGENTS.md

## Cursor Cloud specific instructions

### Overview

This is a simple Node.js web application ("Floor Plan Vectorizer") that uploads floor plan images and converts them to SVG via the external Vectorizer.AI API. No build step, no linter, no test framework is configured.

### Running the dev server

```bash
npm start
```

Server starts on port 8080. The app serves `index.html` at `/` and the vectorize API at `POST /api/vectorize`.

### Configuration

The server requires `config.json` in the project root (gitignored). Copy from `config.json.example` and fill in Vectorizer.AI credentials:

```bash
cp config.json.example config.json
```

Without valid credentials, the server starts fine but the vectorize endpoint returns a 401 error from the upstream API.

### Key caveats

- There are **no automated tests, no linter, and no build step** in this project.
- The only npm dependency is `formidable` (multipart form parser).
- The server uses plain Node.js `http` module — no Express or other framework.
- The external Vectorizer.AI API requires valid `vectorizer_username` and `vectorizer_password` in `config.json`. These correspond to secrets `VECTORIZER_USERNAME` and `VECTORIZER_PASSWORD` if available in the environment.
- A PHP alternative backend also exists (`api/vectorize.php`) but the Node.js server is the recommended development path.
