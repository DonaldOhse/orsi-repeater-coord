#!/usr/bin/env python3
"""Generate ORSI coordination letter PDF"""
import sys, json
from reportlab.lib.pagesizes import letter
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.units import inch
from reportlab.lib import colors
from reportlab.platypus import SimpleDocTemplate, Paragraph, Spacer, Table, TableStyle, HRFlowable
from reportlab.lib.enums import TA_LEFT, TA_CENTER, TA_RIGHT

data = json.load(open(sys.argv[1]))
out  = sys.argv[2]

doc = SimpleDocTemplate(out, pagesize=letter,
    leftMargin=1*inch, rightMargin=1*inch,
    topMargin=0.75*inch, bottomMargin=1*inch)

styles = getSampleStyleSheet()
NAVY = colors.HexColor('#1a3a5c')
GOLD = colors.HexColor('#f59e0b')

header_style = ParagraphStyle('header', fontSize=14, fontName='Helvetica-Bold',
    textColor=NAVY, alignment=TA_CENTER, spaceAfter=2)
sub_style = ParagraphStyle('sub', fontSize=9, fontName='Helvetica',
    textColor=colors.grey, alignment=TA_CENTER, spaceAfter=4)
normal = ParagraphStyle('normal', fontSize=10, fontName='Helvetica',
    leading=14, spaceAfter=8)
bold = ParagraphStyle('bold', fontSize=10, fontName='Helvetica-Bold', leading=14)
right = ParagraphStyle('right', fontSize=10, fontName='Helvetica',
    leading=14, alignment=TA_RIGHT)

story = []

# Header
story.append(Paragraph("OKLAHOMA REPEATER SOCIETY, INC.", header_style))
story.append(Paragraph("Frequency Coordination for the State of Oklahoma", sub_style))
story.append(Paragraph(data.get('org_url','www.oklahomarepeatersociety.org'), sub_style))
story.append(HRFlowable(width="100%", thickness=2, color=NAVY))
story.append(Spacer(1, 0.2*inch))

# Date
story.append(Paragraph(data['letter_date'], right))
story.append(Spacer(1, 0.1*inch))

# Recipient address
story.append(Paragraph(data['recipient_name'], normal))
if data.get('recipient_addr1'):
    story.append(Paragraph(data['recipient_addr1'], normal))
if data.get('recipient_addr2'):
    story.append(Paragraph(data['recipient_addr2'], normal))
story.append(Spacer(1, 0.15*inch))

# RE line
story.append(Paragraph(f"<b>RE: {data['subject_line']}</b>", normal))
story.append(Spacer(1, 0.1*inch))

# Opening
story.append(Paragraph(data['opening'], normal))
story.append(Spacer(1, 0.1*inch))

# Body - split on double newlines into paragraphs
for para in data['body_text'].split('\n\n'):
    para = para.strip()
    if not para: continue
    # Check if it looks like a table/list
    if para.startswith('System:') or para.startswith('Location:') or para.startswith('Status:'):
        lines = para.split('\n')
        for line in lines:
            if ':' in line:
                parts = line.split(':', 1)
                story.append(Paragraph(f"<b>{parts[0]}:</b>{parts[1]}", normal))
    else:
        story.append(Paragraph(para.replace('\n', '<br/>'), normal))
    story.append(Spacer(1, 0.05*inch))

story.append(Spacer(1, 0.2*inch))

# Closing
story.append(Paragraph("Sincerely,", normal))
story.append(Spacer(1, 0.4*inch))
story.append(Paragraph(f"<b>{data['coordinator']}</b>", normal))
story.append(Paragraph(data['coord_title'], normal))
story.append(Paragraph("Oklahoma Repeater Society, Inc.", normal))
story.append(Spacer(1, 0.2*inch))
story.append(HRFlowable(width="100%", thickness=1, color=colors.lightgrey))
story.append(Spacer(1, 0.05*inch))
story.append(Paragraph(
    "Oklahoma Repeater Society, Inc. | Frequency Coordination for the State of Oklahoma",
    ParagraphStyle('footer', fontSize=8, textColor=colors.grey, alignment=TA_CENTER)))

doc.build(story)
print(f"PDF generated: {out}")
