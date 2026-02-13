#!/bin/bash

###############################################################################
# Create Watermark Audio File for Tesotunes
# This script creates a simple beep watermark as a placeholder
# Replace with actual voice recording for production
###############################################################################

echo "🎵 Creating Tesotunes Watermark Audio File..."

# Create watermarks directory
mkdir -p storage/app/watermarks

# Output file path
OUTPUT="storage/app/watermarks/tesotunes_watermark.mp3"

# Check if FFmpeg is installed
if ! command -v ffmpeg &> /dev/null; then
    echo "❌ Error: FFmpeg is not installed"
    echo "Install FFmpeg first:"
    echo "  Ubuntu/Debian: sudo apt-get install ffmpeg"
    echo "  macOS: brew install ffmpeg"
    exit 1
fi

echo "✅ FFmpeg found"

# Generate a simple tone watermark (placeholder)
# In production, replace with actual voice recording
echo "🔊 Generating placeholder watermark (beep tone)..."

ffmpeg -f lavfi -i "sine=frequency=1000:duration=0.5,sine=frequency=1200:duration=0.5" \
    -af "concat=n=2:v=0:a=1,volume=0.3" \
    -b:a 128k \
    -y "$OUTPUT" \
    -loglevel error

if [ $? -eq 0 ]; then
    echo "✅ Watermark file created: $OUTPUT"
    echo ""
    echo "⚠️  IMPORTANT: This is a placeholder beep tone!"
    echo ""
    echo "For production, create a proper watermark:"
    echo "============================================"
    echo ""
    echo "Option 1: Use Online TTS (Easiest)"
    echo "-----------------------------------"
    echo "1. Visit: https://ttsmp3.com/"
    echo "2. Enter: 'tesotunes dot com'"
    echo "3. Select: English (US) voice"
    echo "4. Download MP3"
    echo "5. Replace: $OUTPUT"
    echo ""
    echo "Option 2: Record Yourself"
    echo "-------------------------"
    echo "1. Record: 'tesotunes.com' (2-3 seconds)"
    echo "2. Export as MP3 (128kbps+)"
    echo "3. Save to: $OUTPUT"
    echo ""
    echo "Option 3: Use Google Cloud TTS"
    echo "-------------------------------"
    echo "See: AUDIO_WATERMARKING_GUIDE.md"
    echo ""
    ls -lh "$OUTPUT"
else
    echo "❌ Failed to create watermark file"
    exit 1
fi
