#!/bin/sh
# Reads the litellm key on stdin (never argv, never a file), then runs the
# synthesis generator inside the wiki container as www-data.
read -r BR_LLM_KEY
export BR_LLM_KEY
exec docker exec -i -u www-data \
  -e BR_LLM_KEY \
  -e BR_LLM_BASE="$BR_LLM_BASE" \
  -e BR_LLM_MODEL="$BR_LLM_MODEL" \
  p2pwiki php /var/www/html/p2pwiki-custom/batch-review/generate/gen_synthesis.php "$@"
