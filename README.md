# Open Agent Hackathon 2026 - Skeleton

Project scaffold matching the hackathon setup guide.

## Structure
- .env / .env.example
- smoke_test.py
- agent/llm.py, agent/tools_zetaris.py, agent/main.py

## Quick start
1. Copy .env.example to .env and fill ZETARIS_* and MODEL_*.
2. Install deps: pip install openai python-dotenv requests
3. Test layers: python smoke_test.py
4. Run agent: python -m agent.main

