# llm.py: OpenAI-compatible client for Nemotron via Meterless/NVIDIA
import os
from openai import OpenAI
from dotenv import load_dotenv

load_dotenv()


def get_client() -> OpenAI:
    base = os.getenv('MODEL_BASE_URL')
    key = os.getenv('MODEL_API_KEY')
    if not base or not key:
        raise RuntimeError('MODEL_BASE_URL and MODEL_API_KEY must be set in .env')
    return OpenAI(base_url=base, api_key=key)


def get_model() -> str:
    model = os.getenv('NEMOTRON_MODEL')
    if not model:
        raise RuntimeError('NEMOTRON_MODEL must be set in .env')
    return model
