#!/usr/bin/env python3
"""
WSGI entry point for Pilotis.

Este arquivo adapta o FastAPI (ASGI) para rodar via mod_wsgi (WSGI).
Deve ficar em: /home/app/apps_wsgi/pilotis/pilotis.wsgi
"""
import sys
import os
from pathlib import Path

# Diretorio base da aplicacao
APP_DIR = Path(__file__).resolve().parent

# Adiciona o diretorio da aplicacao ao Python path
sys.path.insert(0, str(APP_DIR))

# Adiciona pacotes instalados localmente (se existir)
SITE_PACKAGES = APP_DIR.parent / ".site-packages"
if SITE_PACKAGES.exists():
    sys.path.insert(0, str(SITE_PACKAGES))

# Carrega variaveis de ambiente do .env
from dotenv import load_dotenv
load_dotenv(APP_DIR / ".env")

# Importa o app FastAPI
from pilotis.main import app as fastapi_app

# Adapta ASGI para WSGI
from a2wsgi import ASGIMiddleware
application = ASGIMiddleware(fastapi_app)
