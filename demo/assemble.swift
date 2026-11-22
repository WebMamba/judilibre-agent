// Builds the demo video from demo/output/spec.json (written by demo/assemble.py): the
// frames become an H.264 track, without sound. Over it, as Core Animation layers: each
// segment's caption with its step, a badge on the waits the cut squeezed, and the
// spotlights on what the user acts on, while the video freezes on it. Only AVFoundation: no ffmpeg needed.
//
// Usage: xcrun swiftc -O demo/assemble.swift -o demo/output/assemble && demo/output/assemble demo/output/spec.json
import AVFoundation
import AppKit
import CoreImage
import QuartzCore

struct Segment: Decodable { let id: String; let start: Double; let duration: Double; let step: String; let caption: String }
struct Wait: Decodable { let start: Double; let duration: Double; let label: String }
struct Box: Decodable { let x: Double; let y: Double; let w: Double; let h: Double }
struct Spot: Decodable { let start: Double; let duration: Double; let label: String; let box: Box }
struct Spec: Decodable { let fps: Int32; let frames: String; let segments: [Segment]; let waits: [Wait]; let spots: [Spot]; let output: String }

let spec = try JSONDecoder().decode(Spec.self, from: Data(contentsOf: URL(fileURLWithPath: CommandLine.arguments[1])))
let size = CGSize(width: 1920, height: 1080)
let scale = 1920.0 / 1600.0 // the browser was recorded at 1600x900
let indigo = NSColor(calibratedRed: 0.31, green: 0.27, blue: 0.9, alpha: 1)
let night = NSColor(calibratedRed: 0.06, green: 0.05, blue: 0.16, alpha: 0.86)
let raw = URL(fileURLWithPath: spec.output).deletingLastPathComponent().appendingPathComponent("raw.mp4")
try? FileManager.default.removeItem(at: raw)

// 1. The frames, as an H.264 video.
let frames = try FileManager.default.contentsOfDirectory(atPath: spec.frames).filter { $0.hasSuffix(".png") }.sorted()
let writer = try AVAssetWriter(outputURL: raw, fileType: .mp4)
let input = AVAssetWriterInput(mediaType: .video, outputSettings: [
    AVVideoCodecKey: AVVideoCodecType.h264, AVVideoWidthKey: size.width, AVVideoHeightKey: size.height,
    AVVideoCompressionPropertiesKey: [AVVideoAverageBitRateKey: 8_000_000, AVVideoProfileLevelKey: AVVideoProfileLevelH264HighAutoLevel],
])
let adaptor = AVAssetWriterInputPixelBufferAdaptor(assetWriterInput: input, sourcePixelBufferAttributes: [
    kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
    kCVPixelBufferWidthKey as String: size.width, kCVPixelBufferHeightKey as String: size.height,
])
writer.add(input)
writer.startWriting()
writer.startSession(atSourceTime: .zero)
let context = CIContext()
for (i, name) in frames.enumerated() {
    while !input.isReadyForMoreMediaData { Thread.sleep(forTimeInterval: 0.005) }
    guard let image = CIImage(contentsOf: URL(fileURLWithPath: spec.frames).appendingPathComponent(name)),
          let pool = adaptor.pixelBufferPool else { continue }
    var buffer: CVPixelBuffer?
    CVPixelBufferPoolCreatePixelBuffer(nil, pool, &buffer)
    guard let pixels = buffer else { continue }
    context.render(image, to: pixels)
    adaptor.append(pixels, withPresentationTime: CMTime(value: CMTimeValue(i), timescale: spec.fps))
    if i % 500 == 0 { print("frames \(i)/\(frames.count)") }
}
input.markAsFinished()
await writer.finishWriting()
guard writer.status == .completed else { fatalError("Writing failed: \(String(describing: writer.error))") }

let composition = AVMutableComposition()
let videoAsset = AVURLAsset(url: raw)
let sourceVideo = try await videoAsset.loadTracks(withMediaType: .video)[0]
let total = try await videoAsset.load(.duration)
let videoTrack = composition.addMutableTrack(withMediaType: .video, preferredTrackID: kCMPersistentTrackID_Invalid)!
try videoTrack.insertTimeRange(CMTimeRange(start: .zero, duration: total), of: sourceVideo, at: .zero)

// 2. The overlays. Text is drawn into images: a CATextLayer does not render in an export.
func picture(_ size: CGSize, _ draw: @escaping (CGRect) -> Void) -> CGImage? {
    let image = NSImage(size: size, flipped: false) { rect in draw(rect); return true }
    var rect = CGRect(origin: .zero, size: size)
    return image.cgImage(forProposedRect: &rect, context: nil, hints: [.ctm: NSAffineTransform(transform: AffineTransform(scale: 2))])
}

func text(_ string: String, _ font: NSFont, _ color: NSColor, center: Bool = false) -> NSAttributedString {
    let style = NSMutableParagraphStyle(); style.alignment = center ? .center : .left; style.lineSpacing = 4
    return NSAttributedString(string: string, attributes: [.font: font, .foregroundColor: color, .paragraphStyle: style])
}

/// Shows a layer from `start` for `duration` seconds: it fades in while sliding up by `rise`, then fades out.
func show(_ layer: CALayer, at start: Double, for duration: Double, rise: CGFloat = 18, pop: Bool = false) {
    layer.opacity = 0
    let fadeIn = min(0.3, duration / 4)
    let opacity = CAKeyframeAnimation(keyPath: "opacity")
    opacity.values = [0, 1, 1, 0]
    opacity.keyTimes = [0, NSNumber(value: fadeIn / duration), NSNumber(value: 1 - 0.2 / duration), 1]
    let move = CAKeyframeAnimation(keyPath: pop ? "transform.scale" : "position.y")
    move.values = pop ? [1.25, 1, 1] : [layer.position.y - rise, layer.position.y, layer.position.y]
    move.keyTimes = [0, NSNumber(value: fadeIn / duration), 1]
    move.timingFunctions = [CAMediaTimingFunction(name: .easeOut), CAMediaTimingFunction(name: .linear)]
    let group = CAAnimationGroup()
    group.animations = [opacity, move]
    group.beginTime = AVCoreAnimationBeginTimeAtZero + start
    group.duration = duration
    group.isRemovedOnCompletion = false
    layer.add(group, forKey: "show")
}

let parent = CALayer(); parent.frame = CGRect(origin: .zero, size: size)
let video = CALayer(); video.frame = parent.frame
parent.addSublayer(video)

// The caption of each step of the dossier, at the bottom: its step in a chip, then the text.
for segment in spec.segments where !segment.caption.isEmpty {
    let chip = text(segment.step.uppercased(), .systemFont(ofSize: 20, weight: .bold), NSColor(calibratedRed: 0.65, green: 0.71, blue: 0.99, alpha: 1))
    let line = text(segment.caption, .systemFont(ofSize: 34, weight: .semibold), .white)
    let width = max(chip.size().width, line.size().width) + 64
    let boxSize = CGSize(width: min(width, 1700), height: 112)
    let box = CALayer()
    box.frame = CGRect(x: (size.width - boxSize.width) / 2, y: 48, width: boxSize.width, height: boxSize.height)
    box.contents = picture(boxSize) { rect in
        night.setFill(); NSBezierPath(roundedRect: rect, xRadius: 20, yRadius: 20).fill()
        indigo.setFill(); NSBezierPath(roundedRect: CGRect(x: 0, y: 0, width: 8, height: rect.height), xRadius: 4, yRadius: 4).fill()
        chip.draw(at: CGPoint(x: 32, y: 70))
        line.draw(at: CGPoint(x: 32, y: 20))
    }
    show(box, at: segment.start + 0.1, for: max(1, segment.duration - 0.15))
    parent.addSublayer(box)
}

// The waits the cut squeezed, with their real duration, at the top right.
for wait in spec.waits {
    let label = text(wait.label, .systemFont(ofSize: 26, weight: .bold), .white)
    let boxSize = CGSize(width: label.size().width + 44, height: 54)
    let badge = CALayer()
    badge.frame = CGRect(x: size.width - boxSize.width - 40, y: size.height - boxSize.height - 40, width: boxSize.width, height: boxSize.height)
    badge.contents = picture(boxSize) { rect in
        indigo.setFill(); NSBezierPath(roundedRect: rect, xRadius: 27, yRadius: 27).fill()
        label.draw(at: CGPoint(x: 22, y: 12))
    }
    show(badge, at: wait.start, for: max(0.6, wait.duration), rise: 0, pop: true)
    parent.addSublayer(badge)
}

// The spotlights: a ring around the element, and its label above it.
for spot in spec.spots {
    // Browser coordinates (top-left origin, 1600x900) to the video's (bottom-left, 1920x1080), kept on screen.
    let x = max(8, spot.box.x * scale - 10), w = min(spot.box.w * scale + 20, size.width - x - 8)
    let top = max(8, spot.box.y * scale - 10), h = min(spot.box.h * scale + 20, size.height - top - 200)
    guard w > 20, h > 20 else { continue }
    let frame = CGRect(x: x, y: size.height - top - h, width: w, height: h)

    let ring = CAShapeLayer()
    ring.frame = frame
    ring.path = CGPath(roundedRect: CGRect(origin: .zero, size: frame.size), cornerWidth: 16, cornerHeight: 16, transform: nil)
    ring.fillColor = indigo.withAlphaComponent(0.10).cgColor
    ring.strokeColor = indigo.cgColor
    ring.lineWidth = 5
    ring.shadowColor = indigo.cgColor; ring.shadowRadius = 18; ring.shadowOpacity = 0.9; ring.shadowOffset = .zero
    show(ring, at: spot.start, for: spot.duration, rise: 0, pop: true)
    parent.addSublayer(ring)

    let label = text(spot.label, .systemFont(ofSize: 24, weight: .bold), .white)
    let tagSize = CGSize(width: label.size().width + 36, height: 46)
    let above = frame.maxY + tagSize.height + 12 < size.height
    let tag = CALayer()
    tag.frame = CGRect(x: min(frame.minX, size.width - tagSize.width - 8), y: above ? frame.maxY + 10 : frame.maxY - tagSize.height - 10, width: tagSize.width, height: tagSize.height)
    tag.contents = picture(tagSize) { rect in
        indigo.setFill(); NSBezierPath(roundedRect: rect, xRadius: 12, yRadius: 12).fill()
        label.draw(at: CGPoint(x: 18, y: 10))
    }
    show(tag, at: spot.start + 0.1, for: spot.duration - 0.1, rise: 10)
    parent.addSublayer(tag)
}

let videoComposition = AVMutableVideoComposition(propertiesOf: composition)
videoComposition.renderSize = size
videoComposition.frameDuration = CMTime(value: 1, timescale: spec.fps)
videoComposition.animationTool = AVVideoCompositionCoreAnimationTool(postProcessingAsVideoLayer: video, in: parent)

// 3. The MP4.
let output = URL(fileURLWithPath: spec.output)
try? FileManager.default.removeItem(at: output)
let export = AVAssetExportSession(asset: composition, presetName: AVAssetExportPreset1920x1080)!
export.videoComposition = videoComposition
try await export.export(to: output, as: .mp4)
try? FileManager.default.removeItem(at: raw)
print("Video: \(output.path) (\(String(format: "%.1f", CMTimeGetSeconds(total)))s)")
